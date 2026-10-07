<?php

namespace App\Http\Controllers;

use App\Models\EmployeeProfile;
use App\Models\Position;
use App\Models\ReviewLog;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\WansisReport;
use App\Services\ApprovalService;
use App\Services\TicketActivityLogger;
use App\Services\WansisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class ApprovalController extends Controller
{
    /** Berapa tiket tertua yang dikirim ke dasbor approver. */
    private const OLDEST_LIMIT = 5;

    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly WansisService $wansisService,
    ) {}

    /**
     * Daftar tiket yang menunggu approval oleh user yang login.
     *
     * stage=INITIAL  → tiket status PENDING_APPROVAL (persetujuan awal)
     * stage=FINAL    → tiket status PENDING_REVIEW (persetujuan penutupan)
     * stage=HISTORY  → tiket yang sudah pernah diapprove / ditolak user ini
     *
     * Approver ditentukan oleh posisi pegawai, jadi query difilter
     * berdasarkan kecocokan approval_type ↔ position user.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stage' => 'nullable|in:INITIAL,FINAL,HISTORY',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $user = $request->user();
        $employee = $user?->employeeProfile;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Approver harus seorang pegawai.',
            ], 403);
        }

        $stage = $validated['stage'] ?? 'INITIAL';
        $perPage = $validated['per_page'] ?? 20;

        $query = Ticket::query()
            ->with([
                'reporterUser',
                'customer.user',
                'category.parent',
                'ticketType',
                'priority',
                'status',
                'vehicleDetail.product',
                'salesDetail',
                'action',
                'latestRevision',
            ]);

        if ($stage === 'HISTORY') {
            // Tiket yang pernah diputuskan approver ini (approve / reject).
            $query->whereHas('reviewLogs', function ($q) use ($employee) {
                $q->where('reviewer_employee_id', $employee->id)
                    ->whereIn('review_type', ['APPROVAL_FINAL', 'APPROVAL_INITIAL'])
                    ->whereIn('decision', ['APPROVE', 'REJECT']);
            });

            // WAJIB: proses persetujuan baru selesai bila tiketnya terminal
            // (CLOSED / REJECTED).
            //
            // Tanpa filter ini, `whereHas(reviewLogs)` saja sudah cukup untuk
            // membuat tiket tampil di arsip begitu approval AWAL disetujui —
            // padahal approval itu dua tahap, dan tiket masih berjalan di
            // unit/handler sebelum approval PENUTUPAN. Akibatnya tiket
            // PENDING_REVIEW maupun IN_PROGRESS ikut muncul di "Arsip &
            // Riwayat" padahal belum selesai.
            $query->whereHas('status', fn ($q) => $q->where('is_terminal', true));
        } else {
            $statusCode = $stage === 'FINAL' ? 'PENDING_REVIEW' : 'PENDING_APPROVAL';

            $query->whereHas('status', fn ($q) => $q->where('code', $statusCode));

            // Hanya tiket yang approval_type-nya cocok dengan posisi pegawai
            $query->where(function ($q) use ($employee) {
                $q->whereNull('approval_type')
                    ->orWhereIn('approval_type', $this->approvalTypesForEmployee($employee));
            });
        }

        $tickets = $query->latest()->paginate($perPage);

        return response()->json($tickets);
    }

    /**
     * GET /api/auth/approver/summary
     *
     * Satu request untuk seluruh angka dasbor approver.
     *
     * Kenapa tidak cukup pakai `index()`: kartu KPI di dasbor memakai
     * `initial.length` dan `final.length`, yaitu **jumlah baris di halaman
     * pertama** (`per_page` default 20), bukan total. Begitupun `latest()`
     * mengurut terbaru dulu, sehingga angkanya bukan cuma terpotong, tapi juga
     * mengambil sampel yang paling baru — kebalikan dari yang biasanya dicari.
     *
     * - kpi: antrean per tahap (count asli) + keputusan bulan berjalan
     * - oldest_pending: 5 tiket tertua gabungan INITIAL & FINAL
     * - avg_decision_hours: rata-rata lama dari tiket dibuat sampai diputuskan
     * - by_stage: persetujuan vs penolakan per tahap
     *
     * Angka "selesai" & "ditolak" sengaja dihitung **sejak awal bulan**,
     * bukan sepanjang waktu, supaya cocok dengan yang dipantau harian.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user?->employeeProfile;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Approver harus seorang pegawai.',
            ], 403);
        }

        $employeeId = $employee->id;
        $monthStart = Carbon::now()->startOfMonth();
        $approvalTypes = $this->approvalTypesForEmployee($employee);

        // Antrean yang memang boleh diputuskan oleh approver ini: statusnya
        // cocok tahap DAN approval_type-nya cocok posisinya.
        $pendingQuery = function (string $statusCode) use ($approvalTypes) {
            return Ticket::query()
                ->whereHas('status', fn ($q) => $q->where('code', $statusCode))
                ->where(function ($q) use ($approvalTypes) {
                    $q->whereNull('approval_type')
                        ->orWhereIn('approval_type', $approvalTypes);
                });
        };

        $initialPending = $pendingQuery('PENDING_APPROVAL')->count();
        $finalPending = $pendingQuery('PENDING_REVIEW')->count();

        // Keputusan approver ini bulan berjalan.
        $myDecisions = DB::table('review_logs')
            ->where('reviewer_employee_id', $employeeId)
            ->whereIn('review_type', ['APPROVAL_INITIAL', 'APPROVAL_FINAL'])
            ->where('reviewed_at', '>=', $monthStart);

        $approvedThisMonth = (clone $myDecisions)->where('decision', 'APPROVE')->count();
        $rejectedThisMonth = (clone $myDecisions)->where('decision', 'REJECT')->count();

        // ── 5 tiket tertua, gabungan kedua tahap ──────────────────────
        // Digabung karena dasbor menampilkan keduanya dalam satu tabel. Kalau
        // dipisah, INITIAL yang jumlahnya lebih besar akan mengisi seluruh slot
        // dan tiket approval penutupan yang paling lama bisa tidak pernah muncul.
        $oldestPending = collect([
            ['status' => 'PENDING_APPROVAL', 'stage' => 'INITIAL'],
            ['status' => 'PENDING_REVIEW', 'stage' => 'FINAL'],
        ])
            ->flatMap(function (array $bucket) use ($pendingQuery) {
                return $pendingQuery($bucket['status'])
                    ->with([
                        'ticketType:id,code',
                        'priority:id,code',
                        'salesDetail:id,ticket_id,so_number',
                        'reporterUser:id,full_name',
                    ])
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->limit(self::OLDEST_LIMIT)
                    ->get()
                    ->map(fn (Ticket $t) => [
                        'stage' => $bucket['stage'],
                        'ticket_no' => $t->ticket_no,
                        'subject' => $t->subject,
                        'ticket_type_code' => $t->ticketType?->code,
                        'priority_code' => $t->priority?->code,
                        'reporter_name' => $t->reporterUser?->full_name ?? 'Pengguna',
                        'so_number' => $t->salesDetail?->so_number,
                        'created_at' => $t->created_at?->toIso8601String(),
                        'age_days' => (int) $t->created_at->startOfDay()->diffInDays(Carbon::now()->startOfDay()),
                    ]);
            })
            ->sortBy('age_days')
            ->take(self::OLDEST_LIMIT)
            ->values();

        // ── Rata-rata lama menunggu keputusan ────────────────────────
        $durations = DB::table('review_logs as rl')
            ->join('tickets as t', 't.id', '=', 'rl.ticket_id')
            ->where('rl.reviewer_employee_id', $employeeId)
            ->whereIn('rl.review_type', ['APPROVAL_INITIAL', 'APPROVAL_FINAL'])
            ->limit(1000)
            ->get(['t.created_at', 'rl.reviewed_at'])
            ->map(function ($row): float {
                // `created->diffInHours(reviewed)` = reviewed - created.
                // Urutannya penting: dibalik hasilnya negatif dan metriknya
                // selalu keluar "0 jam".
                $diff = Carbon::parse($row->created_at)->diffInHours(Carbon::parse($row->reviewed_at), false);

                return $diff > 0 ? (float) $diff : 0.0;
            })
            ->sort()
            ->values();

        // ── Breakdown per tahap ───────────────────────────────────────
        $byStage = DB::table('review_logs')
            ->select('review_type', 'decision', DB::raw('COUNT(*) AS total'))
            ->where('reviewer_employee_id', $employeeId)
            ->whereIn('review_type', ['APPROVAL_INITIAL', 'APPROVAL_FINAL'])
            ->groupBy('review_type', 'decision')
            ->get()
            ->reduce(function (array $carry, $row) {
                $stage = $row->review_type === 'APPROVAL_FINAL' ? 'final' : 'initial';
                $key = $row->decision === 'APPROVE' ? 'approve' : 'reject';
                $carry[$stage][$key] = (int) $row->total;

                return $carry;
            }, ['initial' => ['approve' => 0, 'reject' => 0], 'final' => ['approve' => 0, 'reject' => 0]]);

        return response()->json([
            'kpi' => [
                'initial_pending' => $initialPending,
                'final_pending' => $finalPending,
                'total_pending' => $initialPending + $finalPending,
                'approved_this_month' => $approvedThisMonth,
                'rejected_this_month' => $rejectedThisMonth,
            ],
            'oldest_pending' => $oldestPending,
            'avg_decision_hours' => $durations->isEmpty() ? null : round((float) $durations->avg(), 1),
            'decision_sample' => $durations->count(),
            'by_stage' => $byStage,
            'month_start' => $monthStart->toIso8601String(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Approve / Reject tiket.
     *
     * INITIAL:
     *   APPROVE → PENDING_APPROVAL → IN_PROGRESS
     *              + create ticket_assignments(UNIT) agar unit assign handler
     *   REJECT  → PENDING_APPROVAL → REJECTED + rejection_reason (wajib)
     *
     * FINAL (penutupan):
     *   APPROVE → PENDING_REVIEW → CLOSED
     *   REJECT  → PENDING_REVIEW → REWORK_REQUIRED + rejection_reason (wajib)
     *              (resolusi handler ditolak, bukan tiketnya — kembali ke handler)
     */
    public function decide(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'decision' => 'required|in:APPROVE,REJECT',
            'stage' => 'required|in:INITIAL,FINAL',
            'rejection_reason' => 'required_if:decision,REJECT|string|max:2000',
        ]);

        $ticket = $this->findTicket($id)
            ->load(['status', 'reporterUser.employeeProfile']);

        $user = $request->user();
        $employee = $user?->employeeProfile;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Approver harus seorang pegawai.',
            ], 403);
        }

        // ── Validasi stage ↔ status tiket ──────────────────────────────────
        $stage = $validated['stage'];
        $expectedStatus = $stage === 'FINAL' ? 'PENDING_REVIEW' : 'PENDING_APPROVAL';

        if ($ticket->status->code !== $expectedStatus) {
            return response()->json([
                'success' => false,
                'message' => $stage === 'FINAL'
                    ? 'Tiket ini belum diajukan untuk persetujuan penutupan.'
                    : 'Tiket ini tidak sedang menunggu persetujuan awal.',
            ], 422);
        }

        // ── Validasi kewenangan: posisi harus cocok dengan approval_type ────
        if (! $this->approvalService->canApprove($employee, $ticket)) {
            return response()->json([
                'success' => false,
                'message' => 'Posisi Anda tidak berhak melakukan persetujuan untuk tiket ini.',
            ], 403);
        }

        $decision = $validated['decision'];

        // Diisi di dalam transaksi, tapi request WANSIS-nya dikirim SETELAH
        // commit (lihat akhir method decide()).
        $wansisTicketId = null;

        DB::transaction(function () use ($ticket, $user, $employee, $stage, $decision, $expectedStatus, $validated, &$wansisTicketId) {
            // ── 1. Untuk FINAL, update resolusi PENDING menjadi APPROVED/REJECTED ──
            $resolutionId = null;
            if ($stage === 'FINAL') {
                $resolution = $ticket->resolutions()
                    ->where('review_decision', 'PENDING')
                    ->first();

                if ($resolution) {
                    $resolution->update([
                        'review_decision' => $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED',
                        'reviewed_at' => now(),
                    ]);
                    $resolutionId = $resolution->id;
                }
            }

            // ── 2. Catat review_log ──
            $previousReview = $ticket->reviewLogs()->latest('reviewed_at')->first();

            ReviewLog::create([
                'ticket_id' => $ticket->id,
                'reviewer_employee_id' => $employee->id,
                'review_type' => $stage === 'FINAL' ? 'APPROVAL_FINAL' : 'APPROVAL_INITIAL',
                'decision' => $decision,
                'destination_department_id' => $ticket->destination_department_id,
                'previous_review_id' => $previousReview?->id,
                'resolution_id' => $resolutionId,
                'notes' => $decision === 'REJECT'
                    ? ($validated['rejection_reason'] ?? null)
                    : null,
            ]);

            // ── 3. Update status + alasan penolakan ──
            if ($stage === 'INITIAL') {
                if ($decision === 'APPROVE') {
                    $ticket->update([
                        'status_id' => $this->statusId('IN_PROGRESS'),
                        'rejection_reason' => null,
                    ]);

                    // Unit mengambil alih — siap untuk assign handler
                    $this->createUnitAssignment($ticket, $employee);

                    // Kirim ke WANSIS untuk klaim distribusi (hanya saat INITIAL
                    // APPROVE). Jejak kirim disimpan di tabel `wansis_reports`
                    // supaya `tickets` tetap clean (tanpa kolom wansis_*).
                    // Request HTTP-nya dieksekusi setelah commit (akhir decide())
                    // agar WANSIS yang lambat/mati tidak menahan transaksi approve.
                    $ticket->loadMissing(['category', 'salesDetail']);
                    $wansisCategoryCode = (string) ($ticket->category->code ?? '');

                    if (str_starts_with($wansisCategoryCode, 'KLAIM_DISTRIBUSI')) {
                        $wansisTicketId = $ticket->id;
                    }
                } else {
                    $ticket->update([
                        'status_id' => $this->statusId('REJECTED'),
                        'rejection_reason' => $validated['rejection_reason'],
                    ]);
                }
            } else {
                // FINAL: approve → CLOSED, reject → REWORK_REQUIRED (kembali ke handler)
                if ($decision === 'APPROVE') {
                    $ticket->update([
                        'status_id' => $this->statusId('CLOSED'),
                        'closed_at' => now(),
                        'rejection_reason' => null,
                    ]);
                } else {
                    $ticket->update([
                        'status_id' => $this->statusId('REWORK_REQUIRED'),
                        'rejection_reason' => $validated['rejection_reason'],
                    ]);
                }
            }

            // ── 4. Timeline aktivitas (di dalam transaksi yang sama) ──
            $activityType = match (true) {
                $stage === 'INITIAL' && $decision === 'APPROVE' => TicketActivity::TYPE_APPROVED_INITIAL,
                $stage === 'INITIAL' => TicketActivity::TYPE_REJECTED_INITIAL,
                $decision === 'APPROVE' => TicketActivity::TYPE_APPROVED_FINAL,
                default => TicketActivity::TYPE_REWORK_REQUESTED,
            };
            $activityDescription = match (true) {
                $stage === 'INITIAL' && $decision === 'APPROVE' => 'Tiket disetujui. Diteruskan ke unit untuk ditindaklanjuti.',
                $stage === 'INITIAL' => 'Tiket ditolak approver.',
                $decision === 'APPROVE' => 'Resolusi disetujui. Tiket ditutup.',
                default => 'Resolusi ditolak. Tiket dikembalikan ke handler.',
            };
            TicketActivityLogger::record(
                $ticket,
                $activityType,
                $user->id,
                $activityDescription,
                ['status' => $expectedStatus],
                ['status' => $ticket->fresh()->status->code ?? null],
            );
        });

        // Kirim ke WANSIS SETELAH transaksi commit. Best-effort: kegagalan
        // integrasi tidak boleh membatalkan approval yang sudah tersimpan
        // (jejaknya tetap ada di `wansis_reports` untuk ditelusuri/di-retry).
        if ($wansisTicketId) {
            $this->sendClaimToWansis($wansisTicketId);
        }

        return response()->json([
            'success' => true,
            'message' => $this->successMessage($stage, $decision),
            'data' => $ticket->fresh()->load([
                'reporterUser',
                'customer.user',
                'category.parent',
                'ticketType',
                'priority',
                'status',
                'action',
                'vehicleDetail.product',
                'salesDetail',
                'latestRevision',
            ]),
        ]);
    }

    /**
     * Kirim laporan klaim distribusi ke WANSIS lalu simpan jejaknya di
     * `wansis_reports`.
     *
     * Dipisah dari alur approve supaya request HTTP (timeout 30s x 3 retry)
     * dijalankan SETELAH transaksi commit — endpoint WANSIS yang lambat/mati
     * tidak boleh menahan transaksi DB maupun membatalkan approval.
     *
     * Baris `wansis_reports` selalu dibuat (status `queued`) sebelum request,
     * lalu di-update jadi `sent`/`failed` + pesan error agar kegagalan bisa
     * ditelusuri dan di-retry. Selalu best-effort: tidak pernah melempar
     * exception ke pemanggil.
     */
    private function sendClaimToWansis(int $ticketId): void
    {
        $ticket = Ticket::with(['category.parent', 'salesDetail', 'latestRevision'])->find($ticketId);
        if (! $ticket) {
            return;
        }

        // Payload memakai nilai efektif (origin + koreksi reviewer).
        $payload = $this->wansisService->buildPayload($ticket);

        $report = WansisReport::create([
            'ticket_id' => $ticket->id,
            'jenis_pengajuan' => (string) ($payload['jenisPengajuan'] ?? ''),
            'so_number' => (string) ($payload['soNumber'] ?? ''),
            'payload_json' => $payload ?? [],
            'status' => 'queued',
        ]);

        try {
            $response = $this->wansisService->createWrongDeliveryReport($ticket);

            if ($response && isset($response['success']) && $response['success']) {
                $report->update([
                    'wansis_report_id' => $response['reportId'] ?? null,
                    'response_json' => $response,
                    'sent_at' => now(),
                    'status' => 'sent',
                ]);

                return;
            }

            $report->update([
                'response_json' => is_array($response) ? $response : null,
                'status' => 'failed',
                'error_message' => $payload === null
                    ? 'Payload WANSIS tidak valid (subkategori/SO/barang klaim belum lengkap)'
                    : 'Gagal mengirim ke WANSIS',
            ]);
        } catch (Throwable $e) {
            $report->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * approval_type apa saja yang bisa diproses oleh pegawai ini (berdasarkan posisi).
     *
     * @return string[]
     */
    private function approvalTypesForEmployee(EmployeeProfile $employee): array
    {
        $position = Position::find($employee->position_id);

        if (! $position) {
            return [];
        }

        return match (true) {
            // Supervisor level 4 → DIVISION (departemen mereka)
            $position->hierarchy_level === 4 => ['DIVISION'],
            // Manager level 3 → GENERAL_MANAGER
            $position->hierarchy_level === 3 => ['GENERAL_MANAGER'],
            // Manager Operational → OPERATIONAL_MANAGER
            $position->name === 'Manager Operational' => ['OPERATIONAL_MANAGER'],
            // Level 1 (Komisaris/Direktur) → DIREKSI
            $position->hierarchy_level === 1 => ['DIREKSI'],
            default => [],
        };
    }

    private function createUnitAssignment(Ticket $ticket, EmployeeProfile $approver): void
    {
        // Hindari duplikat kalau sudah ada assignment UNIT
        $exists = $ticket->assignments()
            ->where('assignment_type', 'UNIT')
            ->exists();

        if ($exists) {
            return;
        }

        $ticket->assignments()->create([
            'assignment_type' => 'UNIT',
            'assigned_to_department_id' => $ticket->destination_department_id,
            'assigned_by_employee_id' => $approver->id,
            'assigned_at' => now(),
        ]);
    }

    /**
     * Cari tiket berdasarkan id numerik atau ticket_no.
     *
     * FE memakai ticket_no sebagai identifier publik (mis. TKT-20260922-00009),
     * jadi endpoint publik harus menerima keduanya.
     */
    private function findTicket(string $id): Ticket
    {
        $query = Ticket::query();

        return is_numeric($id)
            ? $query->findOrFail($id)
            : $query->where('ticket_no', $id)->firstOrFail();
    }

    private function statusId(string $code): int
    {
        return DB::table('ticket_statuses')
            ->where('code', $code)
            ->value('id');
    }

    private function successMessage(string $stage, string $decision): string
    {
        if ($decision === 'REJECT') {
            return $stage === 'FINAL'
                ? 'Resolusi ditolak — tiket dikembalikan ke handler untuk diperbaiki.'
                : 'Tiket ditolak.';
        }

        return $stage === 'FINAL'
            ? 'Penutupan tiket disetujui. Tiket telah selesai.'
            : 'Tiket disetujui dan diteruskan ke unit untuk ditindak lanjuti.';
    }
}
