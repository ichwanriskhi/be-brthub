<?php

namespace App\Http\Controllers;

use App\Models\EmployeeProfile;
use App\Models\Position;
use App\Models\ReviewLog;
use App\Models\Ticket;
use App\Models\WansisReport;
use App\Services\ApprovalService;
use App\Services\WansisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ApprovalController extends Controller
{
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
            // Tiket yang pernah diproses approver ini (approve / reject)
            $query->whereHas('reviewLogs', function ($q) use ($employee) {
                $q->where('reviewer_employee_id', $employee->id)
                    ->whereIn('review_type', ['APPROVAL_FINAL', 'APPROVAL_INITIAL'])
                    ->whereIn('decision', ['APPROVE', 'REJECT']);
            });
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

        DB::transaction(function () use ($ticket, $employee, $stage, $decision, $validated, &$wansisTicketId) {
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
