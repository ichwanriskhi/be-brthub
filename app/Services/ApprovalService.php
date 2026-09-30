<?php

namespace App\Services;

use App\Models\EmployeeProfile;
use App\Models\Position;
use App\Models\Ticket;

/**
 * Approver bukan role, tapi posisi (jabatan).
 *
 * Seorang tiket punya approval_type (DIREKSI | GENERAL_MANAGER | OPERATIONAL_MANAGER | DIVISION).
 * Siapa yang berhak approve ditentukan oleh posisi pegawai:
 *
 *   DIREKSI            → Direktur Utama / Komisaris / Direktur Keuangan (hierarchy_level = 1)
 *   OPERATIONAL_MANAGER → Manager Operational (hierarchy_level = 2, department Eksekutif & Direksi)
 *   GENERAL_MANAGER     → Senior Manager / Manager (hierarchy_level = 3)
 *   DIVISION            → Supervisor (hierarchy_level = 4) di departemen tujuan tiket
 */
class ApprovalService
{
    /**
     * Cek apakah pegawai berhak melakukan approval atas tiket ini.
     */
    public function canApprove(EmployeeProfile $employee, Ticket $ticket): bool
    {
        if (! $employee->position_id || ! $ticket->approval_type) {
            return false;
        }

        return $this->matchingPositions($ticket)
            ->contains('id', $employee->position_id);
    }

    /**
     * Daftar position yang berhak approve tiket dengan approval_type tertentu.
     *
     * @return \Illuminate\Support\Collection<int, Position>
     */
    public function matchingPositions(Ticket $ticket)
    {
        return $this->resolvePositions($ticket);
    }

    private function resolvePositions(Ticket $ticket)
    {
        return match ($ticket->approval_type) {
            // Direksi: level 1 (Komisaris, Direktur Utama, Direktur Keuangan)
            'DIREKSI' => Position::where('hierarchy_level', 1)->get(),

            // Operational Manager: jabatan ini saja
            'OPERATIONAL_MANAGER' => Position::where('code', 'OPERATIONAL_MANAGER')
                ->orWhere('name', 'Manager Operational')
                ->get(),

            // General Manager: level 3 (Senior Manager / Manager)
            'GENERAL_MANAGER' => Position::where('hierarchy_level', 3)->get(),

            // Division: Supervisor (level 4) di departemen tujuan tiket
            'DIVISION' => Position::query()
                ->where('hierarchy_level', 4)
                ->when($ticket->destination_department_id, function ($q) use ($ticket) {
                    $q->where('department_id', $ticket->destination_department_id);
                })
                ->get(),

            default => collect(),
        };
    }
}
