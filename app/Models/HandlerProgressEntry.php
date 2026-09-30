<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Entri progres pengerjaan yang diinput handler secara berkala.
 *
 * Terhubung ke ticket_assignments — bukan ke tiket langsung, karena progres
 * adalah jejak pengerjaan oleh handler yang ditugaskan untuk tiket itu.
 */
class HandlerProgressEntry extends Model
{
    protected $table = 'handler_progress_entries';

    protected $fillable = [
        'assignment_id',
        'actor_user_id',
        'note',
        'is_internal',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TicketAssignment::class, 'assignment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Lampiran (foto/dokumen bukti pengerjaan) yang disimpan handler bersama
     * entry ini. Tabel ticket_attachments bersifat polimorfik.
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(TicketAttachment::class, 'attachable');
    }
}
