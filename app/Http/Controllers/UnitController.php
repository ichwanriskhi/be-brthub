<?php

namespace App\Http\Controllers;

use App\Models\EmployeeProfile;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Services\TicketActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unit Teknis — departemen yang menerima tiket setelah approval awal.
 *
 * Alur:
 *   approver approve awal → status IN_PROGRESS + assignment UNIT ke
 *   departemen tujuan → (endpoint ini) unit assign handler → handler
 *   mengerjakan dan submit resolusi.
 */
class UnitController extends Controller
{
    /**
     * Antrean tiket yang sudah diterima departemen unit ini (status
     * IN_PROGRESS) — siap untuk di-assign handler.
     */
    public function queue(Request $request): JsonResponse
    {
        $employee = $request->user()?->employeeProfile;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Unit harus seorang pegawai.',
            ], 403);
        }

        $perPage = min(100, max(1, $request->integer('per_page', 20)));

        // Antrean = tiket IN_PROGRESS yang sudah diterima departemen unit ini
        // dan BELUM punya handler aktif. Bila sudah ada handler, tiket pindah
        // ke "Sedang Dikerjakan" (lihat handler) — bukan antrean lagi.
        $tickets = Ticket::query()
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
                'destinationDepartment',
                'assignments' => fn ($q) => $q->where('assignment_type', 'HANDLER')->where('is_active', true),
                'latestReviewLog.destinationDepartment',
            ])
            ->whereHas('assignments', function ($q) use ($employee) {
                $q->where('assignment_type', 'UNIT')
                    ->where('is_active', true)
                    ->where('assigned_to_department_id', $employee->department_id);
            })
            ->whereHas('status', fn ($q) => $q->where('code', 'IN_PROGRESS'))
            ->whereDoesntHave('assignments', function ($q) {
                $q->where('assignment_type', 'HANDLER')
                    ->where('is_active', true);
            })
            ->latest()
            ->paginate($perPage);

        return response()->json($tickets);
    }

    /**
     * Detail tiket untuk halaman unit (data tiket + riwayat penugasan handler).
     */
    public function show(Request $request, $id): JsonResponse
    {
        $employee = $request->user()?->employeeProfile;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Unit harus seorang pegawai.',
            ], 403);
        }

        $ticket = $this->findTicket($id)->load([
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
            'destinationDepartment',
            'attachments',
            'assignments.assignedToEmployee.user',
            'assignments.assignedByEmployee.user',
            'latestReviewLog.destinationDepartment',
            'activities.actor:id,full_name',
            // Nomor report WANSIS (hanya untuk klaim distribusi).
            'wansisReports:id,ticket_id,wansis_report_id',
        ]);

        return response()->json($ticket);
    }

    /**
     * Riwayat penugasan handler oleh departemen unit ini (semua tiket, tidak
     * peduli status saat ini — selama assignment UNIT-nya menunjuk departemen
     * pegawai yang login).
     */
    public function history(Request $request): JsonResponse
    {
        $employee = $request->user()?->employeeProfile;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Unit harus seorang pegawai.',
            ], 403);
        }

        $perPage = min(100, max(1, $request->integer('per_page', 20)));

        $tickets = Ticket::query()
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
                'destinationDepartment',
                'assignments.assignedToEmployee.user',
                'assignments.assignedByEmployee.user',
                'latestReviewLog.destinationDepartment',
            ])
            ->whereHas('assignments', function ($q) use ($employee) {
                $q->where('assignment_type', 'UNIT')
                    ->where('is_active', true)
                    ->where('assigned_to_department_id', $employee->department_id);
            })
            ->latest()
            ->paginate($perPage);

        return response()->json($tickets);
    }

    /**
     * Assign seorang handler ke tiket (dipilih oleh unit).
     *
     * Membuat ticket_assignments(HANDLER). Handler harus pegawai di departemen
     * yang sama dengan assignment UNIT tiket ini.
     */
    public function assignHandler(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|integer|exists:employee_profiles,id',
        ]);

        $ticket = $this->findTicket($id);

        $unitEmployee = $request->user()?->employeeProfile;

        if (! $unitEmployee) {
            return response()->json([
                'success' => false,
                'message' => 'Unit harus seorang pegawai.',
            ], 403);
        }

        // ── 1. Tiket harus sudah diterima departemen unit ini ──────────────
        $unitAssignment = $ticket->assignments()
            ->where('assignment_type', 'UNIT')
            ->where('is_active', true)
            ->first();

        if (! $unitAssignment) {
            return response()->json([
                'success' => false,
                'message' => 'Tiket belum diterima oleh departemen unit.',
            ], 422);
        }

        if ($unitAssignment->assigned_to_department_id !== $unitEmployee->department_id) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak berhak menugaskan handler untuk tiket di luar departemen Anda.',
            ], 403);
        }

        // ── 2. Handler harus pegawai departemen yang sama & ber-role handler ─
        $handler = EmployeeProfile::with('user')
            ->where('id', $validated['employee_id'])
            ->where('department_id', $unitEmployee->department_id)
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->whereHas('user.roles', fn ($q) => $q->where('name', 'handler'))
            ->first();

        if (! $handler) {
            return response()->json([
                'success' => false,
                'message' => 'Pegawai tidak ditemukan, tidak aktif, atau bukan handler di departemen Anda.',
            ], 422);
        }

        // ── 3. Tutup assignment HANDLER sebelumnya bila ada (ganti handler)
        $ticket->assignments()
            ->where('assignment_type', 'HANDLER')
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'unassigned_at' => now(),
            ]);

        // ── 4. Buat assignment baru ────────────────────────────────────────
        $assignment = $ticket->assignments()->create([
            'assignment_type' => 'HANDLER',
            'assigned_to_employee_id' => $handler->id,
            'assigned_by_employee_id' => $unitEmployee->id,
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        TicketActivityLogger::record(
            $ticket,
            TicketActivity::TYPE_HANDLER_ASSIGNED,
            $request->user()?->id,
            "Handler ditugaskan: {$handler->user?->full_name}.",
            ['status' => 'IN_PROGRESS'],
            ['status' => 'IN_PROGRESS'],
        );

        return response()->json([
            'success' => true,
            'message' => "Tiket berhasil ditugaskan ke {$handler->user?->full_name}.",
            'data' => $assignment->load(['assignedToEmployee.user', 'assignedToDepartment']),
        ]);
    }

    /**
     * Cari tiket berdasarkan id numerik atau ticket_no — FE memakai ticket_no
     * sebagai identifier publik.
     */
    private function findTicket(string $id): Ticket
    {
        $query = Ticket::query();

        return is_numeric($id)
            ? $query->findOrFail($id)
            : $query->where('ticket_no', $id)->firstOrFail();
    }
}
