<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Resolusi yang diajukan handler untuk sebuah tiket.
 *
 * Sebuah tiket bisa punya beberapa resolusi (resolution_no naik) bila
 * pengajuan sebelumnya ditolak approver (status kembali REWORK_REQUIRED).
 * `review_decision` berubah PENDING → APPROVED / REJECTED saat approver
 * memproses persetujuan penutupan.
 */
class TicketResolution extends Model
{
    protected $table = 'ticket_resolutions';

    protected $fillable = [
        'ticket_id',
        'handler_assignment_id',
        'submitted_by_user_id',
        'resolution_no',
        'summary',
        'detail',
        'submitted_at',
        'reviewed_at',
        'review_decision',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function handlerAssignment(): BelongsTo
    {
        return $this->belongsTo(TicketAssignment::class, 'handler_assignment_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /**
     * Lampiran bukti penyelesaian (polimorfik via ticket_attachments).
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(TicketAttachment::class, 'attachable');
    }

    /**
     * Log review yang memproses resolusi ini (catatan reviewer/approver).
     *
     * Satu resolusi punya DUA baris review_logs: SUBMIT (jejak pengajuan
     * handler, notes = ringkasan handler) dan APPROVAL (keputusan approver,
     * notes = alasan penolakan, hanya saat REJECT). `latestOfMany` mengambil
     * baris terbaru = log APPROVAL bila sudah diputus. Tanpa ini, HasOne
     * polos mengembalikan baris SUBMIT sehingga teks handler terbaca sebagai
     * "catatan approver" di FE.
     */
    public function reviewLog(): HasOne
    {
        return $this->hasOne(ReviewLog::class, 'resolution_id')->latestOfMany();
    }
}
