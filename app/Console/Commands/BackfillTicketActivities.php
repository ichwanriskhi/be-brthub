<?php

namespace App\Console\Commands;

use App\Models\HandlerProgressEntry;
use App\Models\Ticket;
use App\Models\TicketActivity;
use Illuminate\Console\Command;

/**
 * Susun ulang timeline `ticket_activities` untuk tiket lama yang lahir
 * sebelum pencatatan aktivitas eksplisit ada.
 *
 * Idempotent: tiket yang sudah punya aktivitas dilewati.
 */
class BackfillTicketActivities extends Command
{
    protected $signature = 'tickets:backfill-activities';

    protected $description = 'Susun timeline ticket_activities dari jejak workflow yang sudah ada';

    public function handle(): int
    {
        $tickets = Ticket::query()
            ->whereDoesntHave('activities')
            ->with([
                'reviewLogs.reviewer',
                'resolutions',
                'assignments.assignedToEmployee.user',
                'assignments.assignedByEmployee.user',
            ])
            ->get();

        $filled = 0;

        foreach ($tickets as $ticket) {
            $rows = [];

            $rows[] = [
                'type' => TicketActivity::TYPE_CREATED,
                'actor' => $ticket->reporter_user_id,
                'description' => 'Laporan dibuat.',
                'at' => $ticket->created_at,
            ];

            foreach ($ticket->reviewLogs as $log) {
                $actorUserId = $log->reviewer?->user_id;
                $at = $log->reviewed_at ?? $log->created_at;

                $rows[] = match ($log->review_type) {
                    'INITIAL' => [
                        'type' => match ($log->decision) {
                            'ROUTE' => TicketActivity::TYPE_REVIEW_ROUTED,
                            'REJECT' => TicketActivity::TYPE_REVIEW_REJECTED,
                            default => TicketActivity::TYPE_REVIEW_REWORK,
                        },
                        'actor' => $actorUserId,
                        'description' => match ($log->decision) {
                            'ROUTE' => 'Tinjauan awal selesai. Tiket diteruskan ke approver.',
                            'REJECT' => 'Laporan ditolak reviewer.',
                            default => 'Tiket dikembalikan untuk diperbaiki.',
                        },
                        'at' => $at,
                    ],
                    'APPROVAL_INITIAL' => [
                        'type' => $log->decision === 'APPROVE'
                            ? TicketActivity::TYPE_APPROVED_INITIAL
                            : TicketActivity::TYPE_REJECTED_INITIAL,
                        'actor' => $actorUserId,
                        'description' => $log->decision === 'APPROVE'
                            ? 'Tiket disetujui. Diteruskan ke unit untuk ditindaklanjuti.'
                            : 'Tiket ditolak approver.',
                        'at' => $at,
                    ],
                    'APPROVAL_FINAL' => [
                        'type' => $log->decision === 'APPROVE'
                            ? TicketActivity::TYPE_APPROVED_FINAL
                            : TicketActivity::TYPE_REWORK_REQUESTED,
                        'actor' => $actorUserId,
                        'description' => $log->decision === 'APPROVE'
                            ? 'Resolusi disetujui. Tiket ditutup.'
                            : 'Resolusi ditolak. Tiket dikembalikan ke handler.',
                        'at' => $at,
                    ],
                    // Tipe lawas (sebelum APPROVAL_*): penutupan final.
                    'FINAL_CLOSURE' => [
                        'type' => TicketActivity::TYPE_APPROVED_FINAL,
                        'actor' => $actorUserId,
                        'description' => 'Resolusi disetujui. Tiket ditutup.',
                        'at' => $at,
                    ],
                    default => null,
                };
            }

            foreach ($ticket->assignments as $assignment) {
                if ($assignment->assignment_type !== 'HANDLER') {
                    continue;
                }
                $rows[] = [
                    'type' => TicketActivity::TYPE_HANDLER_ASSIGNED,
                    'actor' => $assignment->assignedByEmployee?->user_id,
                    'description' => 'Handler ditugaskan: '.($assignment->assignedToEmployee?->user?->full_name ?? '-').'.',
                    'at' => $assignment->assigned_at ?? $assignment->created_at,
                ];
            }

            foreach ($ticket->resolutions as $resolution) {
                $rows[] = [
                    'type' => TicketActivity::TYPE_RESOLUTION_SUBMITTED,
                    'actor' => $resolution->submitted_by_user_id,
                    'description' => "Resolusi #{$resolution->resolution_no} diajukan. Menunggu persetujuan penutupan.",
                    'at' => $resolution->submitted_at ?? $resolution->created_at,
                ];
            }

            $progressEntries = HandlerProgressEntry::query()
                ->whereHas('assignment', fn ($q) => $q->where('ticket_id', $ticket->id))
                ->orderBy('created_at')
                ->get(['actor_user_id', 'created_at']);

            foreach ($progressEntries as $entry) {
                $rows[] = [
                    'type' => TicketActivity::TYPE_PROGRESS,
                    'actor' => $entry->actor_user_id,
                    'description' => TicketActivity::DESCRIPTION_PROGRESS,
                    'at' => $entry->created_at,
                ];
            }

            $rows = array_values(array_filter($rows));
            usort($rows, fn ($a, $b) => $a['at'] <=> $b['at']);

            foreach ($rows as $row) {
                $activity = TicketActivity::create([
                    'ticket_id' => $ticket->id,
                    'actor_user_id' => $row['actor'],
                    'activity_type' => $row['type'],
                    'description' => $row['description'],
                ]);
                $activity->created_at = $row['at'] ?? now();
                $activity->updated_at = $row['at'] ?? now();
                $activity->save();
            }

            $filled++;
        }

        $this->info("Timeline tersusun untuk {$filled} tiket.");

        return self::SUCCESS;
    }
}
