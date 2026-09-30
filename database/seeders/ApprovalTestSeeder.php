<?php

namespace Database\Seeders;

use App\Models\Ticket;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Data uji untuk halaman approver:
 *   - 2 tiket PENDING_APPROVAL (persetujuan awal) dengan approval_type berbeda
 *   - 3 tiket PENDING_REVIEW  (persetujuan penutupan) dengan approval_type berbeda
 *     + 1 ticket_assignments(HANDLER) agar resolusi handler tampil
 *     + 1 review_logs(APPROVAL_INITIAL) sebagai jejak submit handler
 */
class ApprovalTestSeeder extends Seeder
{
    public function run(): void
    {
        $statusOpen = DB::table('ticket_statuses')->where('code', 'PENDING_APPROVAL')->value('id');
        $statusReview = DB::table('ticket_statuses')->where('code', 'PENDING_REVIEW')->value('id');

        $category = DB::table('categories')->whereNull('parent_category_id')->first() ?? DB::table('categories')->first();
        $type = DB::table('ticket_types')->first();
        $priority = DB::table('priority_levels')->first();
        $reporter = DB::table('users')->first();
        $customer = DB::table('customers')->first();

        if (! $category || ! $type || ! $priority) {
            $this->command->error('ApprovalTestSeeder: master data belum lengkap.');

            return;
        }

        // ── Persetujuan awal ──────────────────────────────────────────────
        $initial = [
            ['TKT-APPR-INIT-01', 'DIREKSI'],
            ['TKT-APPR-INIT-02', 'GENERAL_MANAGER'],
        ];

        foreach ($initial as [$no, $approvalType]) {
            Ticket::firstOrCreate(
                ['ticket_no' => $no],
                [
                    'reporter_user_id' => $reporter?->id,
                    'customer_id' => $customer?->id,
                    'category_id' => $category->id,
                    'ticket_type_id' => $type->id,
                    'priority_id' => $priority->id,
                    'status_id' => $statusOpen,
                    'subject' => "Persetujuan awal - {$no}",
                    'description' => 'Tiket uji untuk halaman persetujuan awal approver.',
                    'approval_type' => $approvalType,
                ]
            );
        }

        // ── Persetujuan penutupan ────────────────────────────────────────
        $final = [
            ['TKT-APPR-FINAL-01', 'OPERATIONAL_MANAGER'],
            ['TKT-APPR-FINAL-02', 'DIREKSI'],
            ['TKT-APPR-FINAL-03', 'DIVISION'],
        ];

        foreach ($final as [$no, $approvalType]) {
            $ticket = Ticket::firstOrCreate(
                ['ticket_no' => $no],
                [
                    'reporter_user_id' => $reporter?->id,
                    'customer_id' => $customer?->id,
                    'category_id' => $category->id,
                    'ticket_type_id' => $type->id,
                    'priority_id' => $priority->id,
                    'status_id' => $statusReview,
                    'subject' => "Persetujuan penutupan - {$no}",
                    'description' => 'Tiket uji untuk halaman persetujuan penutupan approver.',
                    'approval_type' => $approvalType,
                ]
            );

            // Jejak: handler submit resolusi (pengajuan penutupan) — ditandai
            // sebagai APPROVAL_INITIAL agar antrean persetujuan penutupan dan
            // previous_review chain tetap konsisten. `RESOLUTION` dipesan untuk
            // resolusi handler yang sesungguhnya (tabel ticket_resolutions).
            DB::table('review_logs')->updateOrInsert(
                ['ticket_id' => $ticket->id, 'review_type' => 'APPROVAL_INITIAL', 'decision' => 'APPROVE'],
                [
                    'reviewer_employee_id' => 2,
                    'destination_department_id' => 4,
                    'notes' => 'Resolusi sudah dikerjakan sesuai SOP.',
                    'reviewed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $this->command->info('ApprovalTestSeeder: 2 tiket persetujuan awal + 3 tiket persetujuan penutupan.');
    }
}
