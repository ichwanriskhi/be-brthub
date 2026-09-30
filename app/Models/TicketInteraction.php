<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Interaksi pada sebuah tiket.
 *
 * Tipe CHAT adalah percakapan antar pihak tiket. `is_internal` membatasi
 * visibilitas pesan: pesan internal hanya untuk staf internal (approver,
 * unit, admin, reviewer, handler), tidak ditampilkan kepada reporter.
 */
class TicketInteraction extends Model
{
    use SoftDeletes;

    protected $table = 'ticket_interactions';

    protected $fillable = [
        'ticket_id',
        'actor_user_id',
        'interaction_type',
        'content',
        'metadata',
        'is_internal',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_internal' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Lampiran pada pesan (polimorfik via ticket_attachments).
     * Opsional — pesan teks tidak memiliki baris attachment.
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(TicketAttachment::class, 'attachable');
    }
}
