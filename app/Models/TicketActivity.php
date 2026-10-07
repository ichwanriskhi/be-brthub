<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketActivity extends Model
{
    use HasFactory;

    public const TYPE_CREATED = 'CREATED';

    public const TYPE_REVIEW_ROUTED = 'REVIEW_ROUTED';

    public const TYPE_REVIEW_REJECTED = 'REVIEW_REJECTED';

    public const TYPE_REVIEW_REWORK = 'REVIEW_REWORK';

    public const TYPE_APPROVED_INITIAL = 'APPROVED_INITIAL';

    public const TYPE_REJECTED_INITIAL = 'REJECTED_INITIAL';

    public const TYPE_HANDLER_ASSIGNED = 'HANDLER_ASSIGNED';

    public const TYPE_PROGRESS = 'PROGRESS';

    /**
     * Deskripsi untuk `TYPE_PROGRESS`.
     *
     * Sengaja tanpa isi catatan: timeline adalah kronologi kejadian, bukan
     * tempat menyimpan teks. Isi progres lengkap (dan lampirannya) sudah
     * ada di `handler_progress_entries` serta card Riwayat Progres, jadi
     * menyalinnya ke sini hanya menghasilkan duplikasi terpotong 120 karakter.
     */
    public const DESCRIPTION_PROGRESS = 'Progres pengerjaan ditambahkan.';

    public const TYPE_RESOLUTION_SUBMITTED = 'RESOLUTION_SUBMITTED';

    public const TYPE_APPROVED_FINAL = 'APPROVED_FINAL';

    public const TYPE_REWORK_REQUESTED = 'REWORK_REQUESTED';

    protected $fillable = [
        'ticket_id',
        'actor_user_id',
        'activity_type',
        'description',
        'old_values',
        'new_values',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
