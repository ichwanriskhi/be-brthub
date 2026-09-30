<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WansisService
{
    protected string $wansisApiUrl;

    protected string $sapApiUrl;

    protected int $timeout;

    protected int $retry;

    public function __construct()
    {
        $this->wansisApiUrl = config('services.wansis.url', 'http://192.168.11.228:3000');
        $this->sapApiUrl = config('services.sap.url', 'http://192.168.11.228:3000');
        $this->timeout = config('services.wansis.timeout', 30);
        $this->retry = config('services.wansis.retry', 3);
    }

    /**
     * Kirim data tiket ke WANSIS untuk pembuatan laporan wrong delivery
     */
    public function createWrongDeliveryReport(Ticket $ticket): ?array
    {
        $payload = $this->mapTicketToWansisPayload($ticket);

        if (! $payload) {
            Log::warning('Ticket tidak dapat dipetakan ke format WANSIS', ['ticket_id' => $ticket->id]);

            return null;
        }

        return $this->sendWithRetry(function () use ($payload) {
            return Http::timeout($this->timeout)
                ->acceptJson()
                ->post("{$this->wansisApiUrl}/api/wrong-delivery-reports", $payload);
        });
    }

    /**
     * Bangun payload WANSIS TANPA mengirim request. Dipakai controller untuk
     * menyimpan snapshot payload ke tabel `wansis_reports` sebelum kirim,
     * supaya apa yang tersimpan == apa yang dikirim.
     */
    public function buildPayload(Ticket $ticket): ?array
    {
        return $this->mapTicketToWansisPayload($ticket);
    }

    /**
     * Cari master data item dari SAP
     */
    public function searchSapItems(string $query, int $page = 1): array
    {
        $cacheKey = "sap_items_{$query}_page_{$page}";
        $cacheTtl = config('services.sap.cache_ttl', 3600);

        return cache()->remember($cacheKey, $cacheTtl, function () use ($query, $page) {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->get("{$this->sapApiUrl}/api/sap/items", [
                    'q' => $query,
                    'page' => $page,
                ]);

            if (! $response->successful()) {
                Log::error('Gagal mengambil data SAP items', [
                    'query' => $query,
                    'page' => $page,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $data = $response->json();

            // Handle format response SAP API: { success: true, data: { data: [...], page: 1, pageSize: 50 } }
            if (isset($data['success']) && $data['success'] === true && isset($data['data']['data']) && is_array($data['data']['data'])) {
                // Return items dengan metadata pagination
                return [
                    'items' => $data['data']['data'],
                    'page' => $data['data']['page'] ?? $page,
                    'pageSize' => $data['data']['pageSize'] ?? 50,
                ];
            }

            // Handle format: { data: [...] }
            if (isset($data['data']) && is_array($data['data'])) {
                return [
                    'items' => $data['data'],
                    'page' => $page,
                    'pageSize' => 50,
                ];
            }

            // Handle format: { items: [...] }
            if (isset($data['items']) && is_array($data['items'])) {
                return [
                    'items' => $data['items'],
                    'page' => $page,
                    'pageSize' => 50,
                ];
            }

            // Fallback: jika response adalah array langsung
            if (is_array($data)) {
                return [
                    'items' => $data,
                    'page' => $page,
                    'pageSize' => 50,
                ];
            }

            return [];
        });
    }

    /**
     * Map data tiket ke format WANSIS — SATU-SATUNYA sumber kebenaran payload.
     * Spec PDF: { soNumber, jenisPengajuan, claims[{correctItemCode,
     * wrongItemCode?, qty, description}] }. Field WAJIB persis; wrongItemCode
     * WAJIB utk SALAH KIRIM/ORDER/SALES ORDER & DILARANG utk 3 jenis lain.
     */
    protected function mapTicketToWansisPayload(Ticket $ticket): ?array
    {
        $effective = $this->effectiveValues($ticket);

        // Kontrak: category_id = child. Bila data lama masih parent → tolak
        // (jenis tak bisa ditentukan) agar tak terkirim payload asal.
        $categoryCode = $effective['categoryCode'];
        if (! str_starts_with($categoryCode, 'KLAIM_DISTRIBUSI')) {
            Log::warning('WANSIS: kategori tiket bukan subkategori klaim distribusi', [
                'ticket_id' => $ticket->id,
                'category_code' => $categoryCode,
            ]);

            return null;
        }

        $soNumber = trim((string) $effective['soNumber']);
        $rawClaims = $effective['claimedItems'];
        if ($soNumber === '' || count($rawClaims) === 0) {
            Log::warning('WANSIS: SO number / daftar barang klaim belum lengkap', [
                'ticket_id' => $ticket->id,
                'so_number' => $soNumber,
                'claim_rows' => count($rawClaims),
            ]);

            return null;
        }

        $jenisPengajuan = $this->mapSubcategoryToJenisPengajuan($categoryCode);
        if (! $jenisPengajuan) {
            return null;
        }

        $needsWrong = in_array($jenisPengajuan, ['SALAH KIRIM', 'SALAH ORDER', 'SALAH SALES ORDER'], true);

        $claims = [];
        foreach ($rawClaims as $claim) {
            if (! is_array($claim)) {
                continue;
            }
            $row = $this->normalizeClaimRow($claim, (string) $ticket->description);
            if (! $row) {
                continue;
            }
            [$code1, $code2, $qty, $description] = $row;

            // Arah correct/wrong per jenis (PDF §1-6). SALAH KIRIM terbalik
            // vs posisi kolom: correct = expected (kolom-2), wrong = returned
            // (kolom-1). SALAH ORDER/SO: correct = kolom-1, wrong = kolom-2.
            // 3 jenis lain: correct saja (strip wrong bila FE mengirim).
            if ($jenisPengajuan === 'SALAH KIRIM') {
                $correctCode = $code2 !== null && $code2 !== '' ? $code2 : $code1;
                $wrongCode = $code2 !== null && $code2 !== '' ? $code1 : null;
            } elseif ($needsWrong) {
                $correctCode = $code1;
                $wrongCode = $code2;
            } else {
                $correctCode = $code1;
                $wrongCode = null;
            }

            $correctCode = trim((string) $correctCode);
            $wrongCode = $wrongCode !== null ? trim((string) $wrongCode) : null;
            if ($correctCode === '' || $qty < 1 || trim($description) === '') {
                continue;
            }
            if ($needsWrong) {
                if (! $wrongCode || $wrongCode === $correctCode) {
                    continue;
                }
                $claims[] = [
                    'correctItemCode' => $correctCode,
                    'wrongItemCode' => $wrongCode,
                    'qty' => (int) $qty,
                    'description' => trim($description),
                ];
            } else {
                $claims[] = [
                    'correctItemCode' => $correctCode,
                    'qty' => (int) $qty,
                    'description' => trim($description),
                ];
            }
        }

        if (count($claims) === 0) {
            Log::warning('WANSIS payload kosong setelah validasi claims', ['ticket_id' => $ticket->id]);
            return null;
        }

        return [
            'soNumber' => $soNumber,
            'jenisPengajuan' => $jenisPengajuan,
            'claims' => $claims,
        ];
    }

    /**
     * Nilai efektif tiket = origin yang sudah di-overlay revisi reviewer
     * (working copy di `ticket_revisions`).
     *
     * Reviewer boleh mengoreksi subkategori + SO/sales + daftar barang, dan
     * koreksi itu TIDAK menimpa origin (kontrak "origin immutable"). Payload
     * WANSIS harus memakai nilai hasil koreksi, kalau tidak integrasi akan
     * mengirim data SO/barang yang salah.
     *
     * @return array{categoryCode: string, soNumber: string, salesName: string, claimedItems: array<int, array<string, mixed>>}
     */
    protected function effectiveValues(Ticket $ticket): array
    {
        $ticket->loadMissing(['category.parent', 'salesDetail', 'latestRevision']);

        $changes = $ticket->latestRevision?->changes;
        $changes = is_array($changes) ? $changes : [];

        // ── Kategori: category_id revisi = id SUBKATEGORI (child) ──────────
        $categoryId = (int) ($ticket->category_id ?? 0);
        $revisedCategoryId = $changes['category_id']['new'] ?? null;
        if ($revisedCategoryId) {
            $categoryId = (int) $revisedCategoryId;
        }
        $category = Category::with('parent')->find($categoryId) ?? $ticket->category;

        // ── Sales detail: overlay SO number / nama sales hasil koreksi ─────
        $soNumber = (string) ($ticket->salesDetail->so_number ?? '');
        $salesName = (string) ($ticket->salesDetail->sales_name ?? '');
        $claims = $ticket->salesDetail->claimed_items ?? [];

        $salesDiff = $changes['sales_detail'] ?? null;
        if (is_array($salesDiff)) {
            if (array_key_exists('so_number', $salesDiff)) {
                $soNumber = (string) ($salesDiff['so_number']['new'] ?? '');
            }
            if (array_key_exists('sales_name', $salesDiff)) {
                $salesName = (string) ($salesDiff['sales_name']['new'] ?? '');
            }
        }

        // ── Daftar barang klaim: pakai versi revisi bila ada ───────────────
        if (isset($changes['claimed_items']) && array_key_exists('new', $changes['claimed_items'])) {
            $claims = $changes['claimed_items']['new'];
        }
        if (is_string($claims)) {
            $decoded = json_decode($claims, true);
            $claims = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($claims)) {
            $claims = [];
        }

        return [
            'categoryCode' => (string) ($category->code ?? ''),
            'soNumber' => $soNumber,
            'salesName' => $salesName,
            'claimedItems' => array_values(array_filter($claims, 'is_array')),
        ];
    }

    /**
     * Normalisasi 1 baris claim FE (tahan 2 format: ClaimRowEntry report/new
     * dgn itemCode1/2+qty+reason, dan TicketItemClaim legacy dgn
     * deliveredItem/replacementItem/partNumber).
     *
     * @return array{string,string|null,int,string}|null [code1, code2, qty, desc]
     */
    protected function normalizeClaimRow(array $claim, string $fallbackDescription): ?array
    {
        $code1 = trim((string) ($claim['itemCode1'] ?? $claim['deliveredItem'] ?? $claim['partNumber'] ?? ''));
        $code2 = trim((string) ($claim['itemCode2'] ?? $claim['replacementItem'] ?? ''));
        $qty = (int) ($claim['qty'] ?? $claim['quantity'] ?? 1);
        $description = trim((string) ($claim['reason'] ?? $claim['description'] ?? $claim['issueDescription'] ?? ''));
        if ($description === '') {
            $description = trim($fallbackDescription);
        }
        if ($code1 === '' && ($code2 === '' || $code2 === null)) {
            return null;
        }
        if ($code1 === '') {
            $code1 = (string) $code2;
            $code2 = null;
        }
        return [$code1, $code2 ?: null, $qty >= 1 ? $qty : 1, $description];
    }

    /**
     * Map subcategory BRT ke jenisPengajuan WANSIS.
     * Input: category CODE (format KLAIM_DISTRIBUSI_{SUFFIX}), bukan nama.
     */
    protected function mapSubcategoryToJenisPengajuan(string $categoryCode): ?string
    {
        $suffix = strtoupper(trim($categoryCode));
        if (str_starts_with($suffix, 'KLAIM_DISTRIBUSI_')) {
            $suffix = substr($suffix, strlen('KLAIM_DISTRIBUSI_'));
        }
        $mapping = [
            'SALAH_KIRIM' => 'SALAH KIRIM',
            'SALAH_SO' => 'SALAH SALES ORDER',
            'KURANG_KIRIM' => 'KURANG KIRIM',
            'BARANG_HILANG' => 'BARANG HILANG',
            'SALAH_ORDER' => 'SALAH ORDER',
            'BARANG_REJECT' => 'BARANG REJECT',
        ];

        return $mapping[$suffix] ?? null;
    }

    /**
     * Kirim request dengan retry logic
     */
    protected function sendWithRetry(callable $request): ?array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->retry; $attempt++) {
            try {
                $response = $request();

                if ($response->successful()) {
                    return $response->json();
                }

                Log::warning('WANSIS request failed', [
                    'attempt' => $attempt,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                if ($attempt < $this->retry) {
                    sleep(1); // Wait 1 second before retry
                }
            } catch (\Exception $e) {
                $lastException = $e;
                Log::error('WANSIS request exception', [
                    'attempt' => $attempt,
                    'message' => $e->getMessage(),
                ]);

                if ($attempt < $this->retry) {
                    sleep(1);
                }
            }
        }

        if ($lastException) {
            throw $lastException;
        }

        return null;
    }
}
