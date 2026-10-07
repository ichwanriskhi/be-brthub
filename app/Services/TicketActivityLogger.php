<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketActivity;

/**
 * Satu-satunya penulis `ticket_activities` — timeline kronologis tiket
 * dari pembuatan sampai selesai. Setiap aksi workflow memanggil
 * `record()` secara eksplisit (mengikuti konvensi ReviewLog::create),
 * bukan via observer, agar penulisnya selalu terlihat di call-site.
 */
class TicketActivityLogger
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public static function record(
        Ticket $ticket,
        string $type,
        ?int $actorUserId,
        string $description,
        array $old = [],
        array $new = []
    ): TicketActivity {
        return TicketActivity::create([
            'ticket_id' => $ticket->id,
            'actor_user_id' => $actorUserId,
            'activity_type' => $type,
            'description' => $description,
            'old_values' => $old === [] ? null : $old,
            'new_values' => $new === [] ? null : $new,
        ]);
    }
}
