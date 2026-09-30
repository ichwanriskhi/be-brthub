<?php

namespace App\Http\Controllers;

use App\Models\HandlerProgressEntry;
use App\Models\ReviewLog;
use App\Models\Ticket;
use App\Models\TicketResolution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Handler — pegawai yang ditugaskan mengerjakan tiket.
 *
 * Tiket hanya muncul di antrean handler bila ada ticket_assignments(HANDLER)
 * aktif yang menunjuk ke pegawai tersebut (dibuat oleh unit setelah approval
 * awal).
 *
 * Alur:
 *   unit assign handler → status IN_PROGRESS
 *   → handler input progres berkala (handler_progress_entries)
 *   → handler submit resolusi → status PENDING_REVIEW
 *   → approver setujui → CLOSED / tolak → REWORK_REQUIRED (kembali ke sini)
 */
class HandlerController extends Controller
{
    /**
     * Relasi eager-load yang dipakai semua respons handler.
     */
    protected function ticketLoads(): array
    {
        return [
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
            'resolutions.attachments',
            'assignments' => fn ($q) => $q->where('assignment_type', 'HANDLER')
                ->where('is_active', true)
                ->with('assignedToEmployee.user'),
        ];
    }

    /**
     * Daftar tiket handler yang sedang login, dikelompokkan per status.
     *
     * ?status=NEED_ACTION|WAITING_REVIEW|REWORK|HISTORY
     */
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()?->employeeProfile;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Handler harus seorang pegawai.',
            ], 403);
        }

        $status = strtoupper($request->input('status', 'NEED_ACTION'));
        $perPage = min(100, max(1, $request->integer('per_page', 20)));

        $statusCodes = match ($status) {
            'NEED_ACTION' => ['IN_PROGRESS'],
            'WAITING_REVIEW' => ['PENDING_REVIEW'],
            'REWORK' => ['REWORK_REQUIRED'],
            'HISTORY' => ['CLOSED', 'REJECTED'],
            default => ['IN_PROGRESS'],
        };

        $query = Ticket::query()
            ->with($this->ticketLoads())
            ->whereHas('assignments', function ($q) use ($employee) {
                $q->where('assignment_type', 'HANDLER')
                    ->where('is_active', true)
                    ->where('assigned_to_employee_id', $employee->id);
            })
            ->whereHas('status', fn ($q) => $q->whereIn('code', $statusCodes));

        // NEED_ACTION = belum mengajukan resolusi yang masih pending
        if ($status === 'NEED_ACTION') {
            $query->whereDoesntHave('resolutions', fn ($q) => $q->where('review_decision', 'PENDING'));
        }

        $tickets = $query->latest()->paginate($perPage);

        return response()->json($tickets);
    }

    /**
     * Detail tiket untuk handler (data lengkap + resolusi + progres).
     */
    public function show(Request $request, $id): JsonResponse
    {
        $employee = $request->user()?->employeeProfile;

        $loads = array_merge($this->ticketLoads(), [
            'resolutions.submittedBy',
        ]);

        $ticket = $this->findTicketForHandler($id, $employee, $loads);

        if (! $ticket) {
            $exists = Ticket::query()
                ->where('ticket_no', $id)
                ->orWhere('id', is_numeric($id) ? (int) $id : 0)
                ->exists();

            return response()->json([
                'success' => false,
                'message' => $exists
                    ? 'Anda tidak ditugaskan untuk tiket ini.'
                    : 'Tiket tidak ditemukan.',
            ], $exists ? 403 : 404);
        }

        // Progres entries lewat assignment aktif
        $progress = HandlerProgressEntry::query()
            ->with('actor:id,full_name', 'attachments')
            ->whereHas('assignment', fn ($q) => $q
                ->where('ticket_id', $ticket->id)
                ->where('assignment_type', 'HANDLER'))
            ->orderByDesc('id')
            ->get();

        return response()->json([
            ...$ticket->toArray(),
            'handler_progress' => $progress,
        ]);
    }

    /**
     * Catat progres pengerjaan (terlihat oleh reporter).
     */
    public function storeProgress(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'note' => 'required|string|max:2000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,webp,gif,pdf,doc,docx,txt|max:5120',
        ]);

        $employee = $request->user()?->employeeProfile;
        $user = $request->user();

        if (! $employee || ! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Handler harus seorang pegawai.',
            ], 403);
        }

        $ticket = $this->findTicketForHandler($id, $employee);

        if (! $ticket) {
            return response()->json([
                'success' => false,
                'message' => 'Tiket tidak ditemukan atau belum ditugaskan kepada Anda.',
            ], 404);
        }

        $assignment = $ticket->assignments
            ->where('assignment_type', 'HANDLER')
            ->where('is_active', true)
            ->first();

        $entry = HandlerProgressEntry::create([
            'assignment_id' => $assignment->id,
            'actor_user_id' => $user->id,
            'note' => $validated['note'],
            'is_internal' => false,
        ]);

        // Lampiran polimorfik (bukti foto/dokumen) — terlihat oleh reporter.
        /** @var UploadedFile[] $files */
        $files = $request->file('attachments', []);
        foreach ($files as $file) {
            $path = $file->store('attachments/progress', 'public');

            $entry->attachments()->create([
                'ticket_id' => $ticket->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'uploaded_by_user_id' => $user->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Progres pengerjaan tersimpan.',
            'data' => $entry->fresh()->load('actor:id,full_name', 'attachments'),
        ]);
    }

    /**
     * Submit resolusi — pengajuan penutupan tiket.
     *
     * - Buat ticket_resolutions (resolution_no naik bila ini pengajuan ulang)
     * - review_logs(RESOLUTION, APPROVE) sebagai jejak pengajuan
     * - Status: IN_PROGRESS → PENDING_REVIEW
     */
    public function submitResolution(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'summary' => 'required|string|max:500',
            'detail' => 'required|string|max:5000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,webp,gif,pdf,doc,docx,txt|max:5120',
        ]);

        $employee = $request->user()?->employeeProfile;
        $user = $request->user();

        if (! $employee || ! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Handler harus seorang pegawai.',
            ], 403);
        }

        $ticket = $this->findTicketForHandler($id, $employee, ['status']);

        if (! $ticket) {
            return response()->json([
                'success' => false,
                'message' => 'Tiket tidak ditemukan atau belum ditugaskan kepada Anda.',
            ], 404);
        }

        // Hanya bisa submit saat tiket sedang dikerjakan atau saat rework
        $allowed = ['IN_PROGRESS', 'REWORK_REQUIRED'];
        if (! in_array($ticket->status->code, $allowed, true)) {
            return response()->json([
                'success' => false,
                'message' => $ticket->status->code === 'PENDING_REVIEW'
                    ? 'Resolusi sudah diajukan — menunggu persetujuan penutupan.'
                    : 'Tiket ini tidak bisa diajukan resolusinya saat ini.',
            ], 422);
        }

        // Bila ada resolusi pending lain, tolak (harusnya tidak terjadi)
        $pendingExists = $ticket->resolutions()
            ->where('review_decision', 'PENDING')
            ->exists();
        if ($pendingExists) {
            return response()->json([
                'success' => false,
                'message' => 'Sudah ada resolusi yang menunggu persetujuan.',
            ], 422);
        }

        $assignment = $ticket->assignments
            ->where('assignment_type', 'HANDLER')
            ->where('is_active', true)
            ->first();

        DB::transaction(function () use ($ticket, $assignment, $user, $employee, $validated, $request) {
            // 1. Resolusi pending sebelumnya ditandai REJECTED (digantikan)
            $ticket->resolutions()
                ->where('review_decision', 'PENDING')
                ->update([
                    'review_decision' => 'REJECTED',
                    'reviewed_at' => now(),
                ]);

            // 2. Buat resolusi baru
            $resolutionNo = ($ticket->resolutions()->max('resolution_no') ?? 0) + 1;

            $resolution = TicketResolution::create([
                'ticket_id' => $ticket->id,
                'handler_assignment_id' => $assignment->id,
                'submitted_by_user_id' => $user->id,
                'resolution_no' => $resolutionNo,
                'summary' => $validated['summary'],
                'detail' => $validated['detail'],
                'submitted_at' => now(),
                'review_decision' => 'PENDING',
            ]);

            // 2.5. Lampiran bukti penyelesaian (polimorfik via ticket_attachments)
            /** @var UploadedFile[] $files */
            $files = $request->file('attachments', []);
            foreach ($files as $file) {
                $path = $file->store('attachments/resolution', 'public');

                $resolution->attachments()->create([
                    'ticket_id' => $ticket->id,
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'mime_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'uploaded_by_user_id' => $user->id,
                ]);
            }

            // 3. Jejak: pengajuan resolusi
            $previousReview = $ticket->reviewLogs()->latest('reviewed_at')->first();

            ReviewLog::create([
                'ticket_id' => $ticket->id,
                'reviewer_employee_id' => $employee->id,
                'review_type' => 'RESOLUTION',
                'decision' => 'SUBMIT',
                'resolution_id' => $resolution->id,
                'previous_review_id' => $previousReview?->id,
                'notes' => $validated['summary'],
            ]);

            // 4. Status → menunggu persetujuan penutupan
            $ticket->update([
                'status_id' => $this->statusId('PENDING_REVIEW'),
                'rejection_reason' => null,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Resolusi diajukan,menunggu persetujuan penutupan.',
            'data' => $ticket->fresh()->load($this->ticketLoads())->load('resolutions.attachments'),
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Cari tiket yang sedang ditugaskan ke handler ini.
     *
     * @param  array<string>  $extraLoads
     */
    private function findTicketForHandler(string $id, $employee, array $extraLoads = []): ?Ticket
    {
        $loads = array_merge($this->ticketLoads(), $extraLoads);

        $query = Ticket::query()
            ->with($loads)
            ->whereHas('assignments', function ($q) use ($employee) {
                $q->where('assignment_type', 'HANDLER')
                    ->where('is_active', true)
                    ->where('assigned_to_employee_id', $employee->id);
            });

        if (is_numeric($id)) {
            $query->where('id', (int) $id);
        } else {
            $query->where('ticket_no', $id);
        }

        return $query->first();
    }

    private function statusId(string $code): int
    {
        return DB::table('ticket_statuses')
            ->where('code', $code)
            ->value('id');
    }
}
