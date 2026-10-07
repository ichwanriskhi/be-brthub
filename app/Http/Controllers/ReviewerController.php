<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ringkasan dasbor reviewer.
 *
 * Kenapa endpoint terpisah, bukan filter di `TicketController::index()`:
 * kartu "Perlu Tindakan Segera" butuh tiket OPEN **paling lama**, sedangkan
 * `index()` selalu `latest()`. Meniru pola "tarik 100 terbaru lalu urutkan
 * ulang di klien" hanya memberi 5 tiket tertua *dari sampel 100* — kalau ada
 * lebih dari 100 tiket OPEN, tiket yang tertua tidak pernah terlihat, padahal
 * judul kartunya menjanjikan sebaliknya.
 */
class ReviewerController extends Controller
{
    /**
     * GET /api/auth/reviewer/summary
     *
     * - kpi: antrean OPEN, menunggu approval, dan rekap keputusan reviewer ini
     * - oldest_open: 5 tiket OPEN tertua (urut `created_at`, di server)
     * - recent_reviews: 5 tiket terakhir yang benar-benar ditinjau reviewer
     *   ini, diurutkan `review_logs.reviewed_at` — bukan `tickets.created_at`
     * - avg_review_hours: rata-rata lama dari tiket dibuat sampai ditinjau
     *
     * Tidak ada blok `decision_breakdown` terpisah: isinya persis sama dengan
     * `kpi.routed_total` dan `kpi.rejected_by_me`, jadi melaporkannya di dua
     * tempat hanya mengulang angka yang sama.
     *
     * `REQUEST_REWORK` tidak dilaporkan di mana pun: backend menerimanya di
     * validasi `submitReview`, tapi tidak ada layar reviewer yang mengirimnya —
     * halaman tinjauan hanya punya tombol Teruskan (ROUTE) dan Tolak (REJECT).
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user?->employeeProfile;

        if ($employee === null) {
            return response()->json([
                'success' => false,
                'message' => 'Reviewer harus seorang pegawai.',
            ], 403);
        }

        $employeeId = $employee->id;

        // Hanya jejak routing reviewer. `review_type` INITIAL; keputusan
        // approver (APPROVAL_INITIAL / APPROVAL_FINAL) bukan riwayat reviewer,
        // walau ditulis orang sama saat satu pegawai memegang dua peran.
        $myInitialLogs = DB::table('review_logs')
            ->where('reviewer_employee_id', $employeeId)
            ->where('review_type', 'INITIAL');

        $monthStart = Carbon::now()->startOfMonth();

        $reviewedThisMonth = (clone $myInitialLogs)
            ->where('reviewed_at', '>=', $monthStart)
            ->count();

        $decisionBreakdown = (clone $myInitialLogs)
            ->select('decision', DB::raw('COUNT(*) AS total'))
            ->groupBy('decision')
            ->pluck('total', 'decision');

        // ── KPI ────────────────────────────────────────────────────────
        // Hanya OPEN. `REWORK_REQUIRED` sengaja tidak dihitung: status itu
        // dibuat saat approver menolak pada tahap penutupan, dan tiketnya
        // kembali ke unit/handler — bukan masuk antrean reviewer. Halaman
        // `/reviewer/tinjauan-awal` juga hanya memfilter `status_code=OPEN`,
        // jadi angka ini harus sama dengan isi antrean itu.
        $openCount = $this->countByStatus('OPEN');
        $waitingApprovalCount = $this->countByStatus('PENDING_APPROVAL');

        // Tiket yang saya teruskan bulan ini dan masih menunggu approval.
        //
        // `whereHas()` hanya ada di Eloquent Builder, bukan Query Builder, dan
        // di sini kita bekerja pada `DB::table('review_logs')` — memakainya
        // akan menghasilkan SQL aneh seperti `` `has` = ticket ``. Subquery
        // lewat `whereIn` dipakai sebagai gantinya.
        $forwardedWaiting = (clone $myInitialLogs)
            ->where('reviewed_at', '>=', $monthStart)
            ->where('decision', 'ROUTE')
            ->whereIn('ticket_id', Ticket::query()
                ->select('id')
                ->whereHas('status', fn ($sq) => $sq->where('code', 'PENDING_APPROVAL')))
            ->count();

        $kpi = [
            'open' => $openCount,
            'waiting_approval' => $waitingApprovalCount,
            'reviewed_this_month' => $reviewedThisMonth,
            'forwarded_waiting' => $forwardedWaiting,
            'rejected_by_me' => (int) ($decisionBreakdown['REJECT'] ?? 0),
            'routed_total' => (int) ($decisionBreakdown['ROUTE'] ?? 0),
        ];

        // ── Rata-rata lama menunggu tinjauan ───────────────────────────
        // Diukur dari `tickets.created_at` ke `review_logs.reviewed_at` pada
        // log INITIAL milik reviewer ini. Nullable: tanpa sampel, menampilkan
        // "0 jam" akan terbaca sebagai "sangat cepat" — itu tidak jujur.
        $avgReviewHours = null;
        $reviewSample = 0;

        $durations = DB::table('review_logs as rl')
            ->join('tickets as t', 't.id', '=', 'rl.ticket_id')
            ->where('rl.reviewer_employee_id', $employeeId)
            ->where('rl.review_type', 'INITIAL')
            ->whereNotNull('rl.reviewed_at')
            ->limit(1000)
            ->get(['t.created_at', 'rl.reviewed_at']);

        if ($durations->isNotEmpty()) {
            $hours = $durations
                ->map(function ($row): float {
                    $created = Carbon::parse($row->created_at);
                    $reviewed = Carbon::parse($row->reviewed_at);

                    // `created->diffInHours(reviewed)` = reviewed - created.
                    // Urutannya penting: dibalik, hasilnya negatif lalu
                    // dipotong jadi 0 dan metriknya selalu keluar "0 jam".
                    $diff = $created->diffInHours($reviewed, false);

                    return $diff > 0 ? (float) $diff : 0.0;
                })
                ->sort()
                ->values();

            $reviewSample = $hours->count();
            $avgReviewHours = $hours->isEmpty() ? null : round((float) $hours->avg(), 1);
        }

        // ── 5 tiket OPEN tertua ────────────────────────────────────────
        $oldestOpen = $this->oldestOpenQuery()
            ->limit(5)
            ->get()
            ->map(fn (Ticket $ticket) => $this->ticketRow($ticket, ageDays: true))
            ->values();

        // ── 5 tinjauan terakhir milik reviewer ini ────────────────────
        // Urut dari `review_logs.reviewed_at`. Versi lama memakai
        // `reviewed_by_me=true` pada `index()` yang mengurut `tickets.created_at`
        // — jadi "terakhir Anda tinjau" sebenarnya menampilkan tiket paling
        // baru dibuat, bukan yang paling baru Anda tangani.
        //
        // Log-nya diambil dulu lewat query builder (butuh `reviewed_at` yang
        // tidak ada di model Ticket), lalu tiket-nya dimuat lewat Eloquent
        // supaya relasi `salesDetail` ikut terbawa. `sales_detail` bukan kolom
        // di tabel `tickets` — kolom itu ada di `ticket_sales_details`.
        $recentLogs = DB::table('review_logs')
            ->where('reviewer_employee_id', $employeeId)
            ->where('review_type', 'INITIAL')
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['ticket_id', 'reviewed_at'])
            ->keyBy('ticket_id');

        $recentReviews = Ticket::query()
            ->with([
                'ticketType:id,code',
                'status:id,code',
                'reporterUser:id,full_name',
                'salesDetail:id,ticket_id,so_number',
            ])
            ->whereIn('id', $recentLogs->keys())
            ->get()
            ->map(function (Ticket $ticket) use ($recentLogs) {
                $row = $recentLogs->get($ticket->id);

                return $this->ticketRow($ticket, ageDays: false) + [
                    'reviewed_at' => Carbon::parse($row->reviewed_at)->toIso8601String(),
                ];
            })
            ->sortByDesc(fn (array $row) => $row['reviewed_at'])
            ->values();

        return response()->json([
            'kpi' => $kpi,
            'avg_review_hours' => $avgReviewHours,
            'review_sample' => $reviewSample,
            'oldest_open' => $oldestOpen,
            'recent_reviews' => $recentReviews,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /** Tiket OPEN, diurutkan paling lama dulu. */
    private function oldestOpenQuery()
    {
        return Ticket::query()
            ->with(['ticketType:id,code', 'status:id,code', 'reporterUser:id,full_name', 'salesDetail:id,ticket_id,so_number'])
            ->whereHas('status', fn ($q) => $q->where('code', 'OPEN'))
            ->orderBy('created_at')
            ->orderBy('id');
    }

    private function countByStatus(string $code): int
    {
        return Ticket::query()
            ->whereHas('status', fn ($q) => $q->where('code', $code))
            ->count();
    }

    /** Bentuk baris tiket yang dipakai frontend (cukup untuk tabel ringkas). */
    private function ticketRow(Ticket $ticket, bool $ageDays): array
    {
        return [
            'id' => (int) $ticket->id,
            'ticket_no' => $ticket->ticket_no,
            'subject' => $ticket->subject,
            'ticket_type_code' => $ticket->ticketType?->code,
            'status_code' => $ticket->status?->code,
            'reporter_name' => $ticket->reporterUser?->full_name ?? 'Pengguna',
            'so_number' => $ticket->salesDetail?->so_number,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'age_days' => $ageDays
                ? (int) $ticket->created_at->startOfDay()->diffInDays(Carbon::now()->startOfDay())
                : 0,
        ];
    }
}
