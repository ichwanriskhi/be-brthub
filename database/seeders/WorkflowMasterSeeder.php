<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WorkflowMasterSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ticket Statuses
        $statuses = [
            ['code' => 'OPEN', 'name' => 'Buka (Belum Ditindaklanjuti)', 'is_terminal' => false, 'sort_order' => 1],
            ['code' => 'PENDING_APPROVAL', 'name' => 'Menunggu Persetujuan Approver', 'is_terminal' => false, 'sort_order' => 2],
            ['code' => 'IN_PROGRESS', 'name' => 'Sedang Diproses', 'is_terminal' => false, 'sort_order' => 3],
            ['code' => 'PENDING_REVIEW', 'name' => 'Menunggu Review', 'is_terminal' => false, 'sort_order' => 4],
            ['code' => 'REWORK_REQUIRED', 'name' => 'Perlu Revisi', 'is_terminal' => false, 'sort_order' => 5],
            ['code' => 'REJECTED', 'name' => 'Ditolak', 'is_terminal' => true, 'sort_order' => 6],
            ['code' => 'CLOSED', 'name' => 'Ditutup/Selesai', 'is_terminal' => true, 'sort_order' => 7],
        ];

        foreach ($statuses as $status) {
            DB::table('ticket_statuses')->updateOrInsert(
                ['code' => $status['code']],
                [
                    'name' => $status['name'],
                    'is_terminal' => $status['is_terminal'],
                    'sort_order' => $status['sort_order'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // 2. Ticket Types
        $types = [
            ['code' => 'REQUEST', 'name' => 'Permintaan'],
            ['code' => 'INCIDENT', 'name' => 'Insiden / Masalah'],
            ['code' => 'COMPLAINT', 'name' => 'Keluhan / Komplain'],
            ['code' => 'INQUIRY', 'name' => 'Pertanyaan / Informasi'],
        ];

        foreach ($types as $type) {
            DB::table('ticket_types')->updateOrInsert(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // 3. Priority Levels
        $priorities = [
            ['code' => 'A', 'name' => 'Tinggi', 'sort_order' => 1],
            ['code' => 'B', 'name' => 'Normal', 'sort_order' => 2],
            ['code' => 'C', 'name' => 'Rendah', 'sort_order' => 3],
        ];

        foreach ($priorities as $priority) {
            DB::table('priority_levels')->updateOrInsert(
                ['code' => $priority['code']],
                [
                    'name' => $priority['name'],
                    'sort_order' => $priority['sort_order'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
