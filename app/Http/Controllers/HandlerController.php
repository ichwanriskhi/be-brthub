<?php

namespace App\Http\Controllers;

use App\Models\HandlerProgressEntry;
use App\Models\ReviewLog;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketResolution;
use App\Services\TicketActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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
    /** Berapa tiket tertua yang dikirim ke dasbor handler. */
    private const OLDEST_LIMIT = 5;

    /**
     * Berapa baris yang diambil dari **setiap sumber** aktivitas (progres dan
     * resolusi) sebelum digabung dan dipotong 5 teratas.
     *
     * Cukup 5, bukan 10: bila sebuah baris termasuk 5 teratas keseluruhan,
     * maka paling banyak hanya 4 baris yang lebih baru darinya di sumbernya
     * sendiri — jadi ia pasti ikut 5 teratas sumber itu. Mengambil lebih dari
     * 5 per sumber hanya membuang query.
     */
    private const ACTIVITY_LIMIT = 5;

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
            'activities.actor:id,full_name',
            // Hanya di `show`, bukan di `ticketLoads()` — list handler tidak
            // menampilkan nomor report sehingga tidak perlu query tambahan.
            'wansisReports:id,ticket_id,wansis_report_id',
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
     * GET /api/auth/handler/summary
     *
     * Satu request untuk seluruh angka dasbor handler.
     *
     * Kenapa tidak cukup pakai `index()`: dasbor memakai
     * `byStatus.NEED_ACTION.length` dan sejenisnya, yaitu **jumlah baris di
     * halaman pertama** (`per_page` default 20), bukan total. Ditambah
     * `latest()` mengurut terbaru dulu, sehingga angka itu bukan cuma terpotong
     * tapi juga mengambil sampel yang paling baru.
     *
     * - kpi: antrean per status (count asli) + hasil kerja handler
     * - oldest_need_action: 5 tiket tertua yang wajib dikerjakan (IN_PROGRESS
     *   + REWORK_REQUIRED), urut di server
     * - avg_resolution_hours: rata-rata dari assignment dibuat sampai resolusi
     * - recent_activity: aktivitas terakhir **dengan nomor tiket**
     */
    public function summary(Request $request): JsonResponse
    {
        $employee = $request->user()?->employeeProfile;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Handler harus seorang pegawai.',
            ], 403);
        }

        $employeeId = $employee->id;

        // Scope tiket: ada assignment HANDLER aktif untuk pegawai ini.
        $myTickets = fn (?string $statusCode = null) => Ticket::query()
            ->whereHas('assignments', fn ($q) => $q
                ->where('assignment_type', 'HANDLER')
                ->where('is_active', true)
                ->where('assigned_to_employee_id', $employeeId))
            ->when(
                $statusCode,
                fn ($q, $code) => $q->whereHas('status', fn ($sq) => $sq->where('code', $code))
            );

        // NEED_ACTION punya syarat tambahan: belum ada resolusi yang masih
        // PENDING. Tanpa ini, tiket yang resolusinya sedang menunggu approval
        // ikut terhitung sebagai "perlu tindakan".
        $needAction = $myTickets('IN_PROGRESS')
            ->whereDoesntHave('resolutions', fn ($q) => $q->where('review_decision', 'PENDING'))
            ->count();

        $waitingReview = $myTickets('PENDING_REVIEW')->count();
        $rework = $myTickets('REWORK_REQUIRED')->count();
        $history = $myTickets()
            ->whereHas('status', fn ($q) => $q->whereIn('code', ['CLOSED', 'REJECTED']))
            ->count();

        // ── Hasil kerja ──────────────────────────────────────────────
        $progressCount = HandlerProgressEntry::query()
            ->whereHas('assignment', fn ($q) => $q
                ->where('assignment_type', 'HANDLER')
                ->where('assigned_to_employee_id', $employeeId))
            ->count();

        $resolutions = DB::table('ticket_resolutions')
            ->where('submitted_by_user_id', $employee->user_id)
            ->get(['id', 'ticket_id', 'resolution_no', 'review_decision']);

        // Rework = resolusi milik handler ini yang ditolak approver.
        //
        // WAJIB disaring `submitted_by_user_id`. Tanpa itu, satu penolakan di
        // tiket siapa pun ikut terhitung sebagai rework handler yang sedang
        // membuka dasbornya — dan angkanya bisa lebih besar daripada seluruh
        // aktivitas handler tersebut.
        $reworkedTicketIds = DB::table('ticket_resolutions')
            ->where('submitted_by_user_id', $employee->user_id)
            ->where('review_decision', 'REJECTED')
            ->distinct()
            ->pluck('ticket_id');

        $reworkRate = $resolutions->isEmpty()
            ? null
            : round(
                $resolutions->where('review_decision', 'REJECTED')->count() / $resolutions->count() * 100,
                1
            );

        // ── 5 tiket tertua yang perlu tindakan ────────────────────────
        //
        // IN_PROGRESS yang belum mengajukan resolusi + REWORK_REQUIRED yang
        // dikembalikan approver. Keduanya adalah status "wajib kerja handler"
        // (submit resolusi hanya diizinkan di dua status ini) — PENDING_REVIEW
        // menunggu approver, bukan handler. Urut tertua lintas kedua status.
        $oldestNeedAction = $myTickets()
            ->whereHas('status', fn ($q) => $q->whereIn('code', ['IN_PROGRESS', 'REWORK_REQUIRED']))
            ->whereDoesntHave('resolutions', fn ($q) => $q->where('review_decision', 'PENDING'))
            ->with([
                'ticketType:id,code',
                'priority:id,code',
                'salesDetail:id,ticket_id,so_number',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::OLDEST_LIMIT)
            ->get()
            ->map(function (Ticket $ticket) {
                return [
                    'ticket_no' => $ticket->ticket_no,
                    'subject' => $ticket->subject,
                    'ticket_type_code' => $ticket->ticketType?->code,
                    'priority_code' => $ticket->priority?->code,
                    'status_code' => $ticket->status?->code,
                    'so_number' => $ticket->salesDetail?->so_number,
                    'created_at' => $ticket->created_at?->toIso8601String(),
                    'age_days' => (int) $ticket->created_at->startOfDay()->diffInDays(Carbon::now()->startOfDay()),
                ];
            })
            ->values();

        // ── Rata-rata lama sampai resolusi diajukan ──────────────────
        $durations = DB::table('ticket_resolutions as tr')
            ->join('ticket_assignments as ta', 'ta.id', '=', 'tr.handler_assignment_id')
            ->where('ta.assigned_to_employee_id', $employeeId)
            ->whereNotNull('tr.submitted_at')
            ->limit(1000)
            ->get(['ta.created_at as assigned_at', 'tr.submitted_at'])
            ->map(function ($row): float {
                // `assigned->diffInHours(submitted)` = submitted - assigned.
                // Urutannya penting: dibalik, hasilnya negatif dan metriknya
                // selalu keluar "0 jam".
                $diff = Carbon::parse($row->assigned_at)->diffInHours(Carbon::parse($row->submitted_at), false);

                return $diff > 0 ? (float) $diff : 0.0;
            })
            ->sort()
            ->values();

        // ── Aktivitas terbaru ────────────────────────────────────────
        // Dua sumber digabung: entri progres dan pengajuan resolusi.
        //
        // Nomor tiket ikut diambil karena UI butuh tautan ke detail — versi
        // lama di frontend kehilangan field ini sehingga di layar hanya
        // muncul teks polos "Tiket" tanpa apa pun di belakangnya.
        $progressActivity = DB::table('handler_progress_entries as hpe')
            ->join('ticket_assignments as ta', 'ta.id', '=', 'hpe.assignment_id')
            ->join('tickets as t', 't.id', '=', 'ta.ticket_id')
            ->where('ta.assignment_type', 'HANDLER')
            ->where('ta.assigned_to_employee_id', $employeeId)
            ->orderByDesc('hpe.created_at')
            ->limit(self::ACTIVITY_LIMIT)
            ->get(['t.ticket_no', 'hpe.note', 'hpe.created_at'])
            ->map(fn ($row) => [
                'kind' => 'progress',
                'label' => 'menambahkan progres',
                'ticket_no' => $row->ticket_no,
                'note' => $row->note,
                'at' => Carbon::parse($row->created_at)->toIso8601String(),
            ]);

        $resolutionActivity = DB::table('ticket_resolutions as tr')
            ->join('tickets as t', 't.id', '=', 'tr.ticket_id')
            ->where('tr.submitted_by_user_id', $employee->user_id)
            ->orderByDesc('tr.submitted_at')
            ->limit(self::ACTIVITY_LIMIT)
            ->get(['t.ticket_no', 'tr.summary', 'tr.resolution_no', 'tr.submitted_at'])
            ->map(fn ($row) => [
                'kind' => 'resolution',
                'label' => 'mengajukan resolusi',
                'ticket_no' => $row->ticket_no,
                'resolution_no' => (int) $row->resolution_no,
                'note' => $row->summary,
                'at' => Carbon::parse($row->submitted_at)->toIso8601String(),
            ]);

        $recentActivity = $progressActivity
            ->merge($resolutionActivity)
            ->sortByDesc('at')
            ->take(self::ACTIVITY_LIMIT)
            ->values();
        // Hasilnya sudah persis `ACTIVITY_LIMIT` baris atau kurang — frontend
        // tidak perlu memotong lagi.

        return response()->json([
            'kpi' => [
                'need_action' => $needAction,
                'waiting_review' => $waitingReview,
                'rework' => $rework,
                'history' => $history,
                'progress_count' => $progressCount,
                'resolutions_submitted' => $resolutions->count(),
                'reworked_tickets' => $reworkedTicketIds->count(),
                'rework_rate' => $reworkRate,
            ],
            'oldest_need_action' => $oldestNeedAction,
            'avg_resolution_hours' => $durations->isEmpty() ? null : round((float) $durations->avg(), 1),
            'resolution_sample' => $durations->count(),
            'recent_activity' => $recentActivity,
            'generated_at' => now()->toIso8601String(),
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

        TicketActivityLogger::record(
            $ticket,
            TicketActivity::TYPE_PROGRESS,
            $user->id,
            TicketActivity::DESCRIPTION_PROGRESS,
        );

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

        $previousStatusCode = $ticket->status->code ?? null;
        $resolutionNo = $ticket->resolutions()->max('resolution_no') ?? 1;
        TicketActivityLogger::record(
            $ticket,
            TicketActivity::TYPE_RESOLUTION_SUBMITTED,
            $user->id,
            "Resolusi #{$resolutionNo} diajukan. Menunggu persetujuan penutupan.",
            $previousStatusCode ? ['status' => $previousStatusCode] : [],
            ['status' => 'PENDING_REVIEW'],
        );

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
