<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ticket extends Model
{
    protected $table = 'tickets';

    protected $guarded = ['id'];

    protected $casts = [
        'closed_at' => 'datetime',
    ];

    public function reporterUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class, 'ticket_type_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(PriorityLevel::class, 'priority_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(TicketStatus::class, 'status_id');
    }

    public function vehicleDetail(): HasOne
    {
        return $this->hasOne(TicketVehicleDetail::class, 'ticket_id');
    }

    public function salesDetail(): HasOne
    {
        return $this->hasOne(TicketSalesDetail::class, 'ticket_id');
    }

    public function relations(): HasMany
    {
        return $this->hasMany(TicketRelation::class, 'ticket_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class, 'ticket_id');
    }

    /**
     * Aksi handler yang dipilih reviewer untuk tiket ini.
     */
    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class, 'action_id');
    }

    /**
     * Departemen tujuan yang dipilih reviewer (dari review log terakhir).
     */
    public function destinationDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'destination_department_id');
    }

    /**
     * Assignments ke unit/handler untuk pengerjaan tiket ini.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TicketAssignment::class, 'ticket_id');
    }

    /**
     * Jejak alur tiket:
     *  INITIAL          — routing reviewer (ROUTE / REQUEST_REWORK / REJECT)
     *  APPROVAL_INITIAL — keputusan approver untuk persetujuan awal
     *  APPROVAL_FINAL   — keputusan approver untuk persetujuan penutupan
     *  RESOLUTION       — resolusi yang dikerjakan handler (fitur handler)
     */
    public function reviewLogs(): HasMany
    {
        return $this->hasMany(ReviewLog::class, 'ticket_id');
    }

    /**
     * Review log terbaru (yang paling terakhir dibuat).
     */
    public function latestReviewLog(): HasOne
    {
        return $this->hasOne(ReviewLog::class, 'ticket_id')->latestOfMany();
    }

    /**
     * Working copy / hasil penyesuaian reviewer (JSON diff, tidak override origin).
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(TicketRevision::class, 'ticket_id');
    }

    /**
     * Revisi working copy terbaru (yang harus ditampilkan ke reviewer).
     */
    public function latestRevision(): HasOne
    {
        return $this->hasOne(TicketRevision::class, 'ticket_id')
            ->latest('revision_no');
    }

    /**
     * Resolusi yang diajukan handler (bisa beberapa kali bila rework).
     */
    public function resolutions(): HasMany
    {
        return $this->hasMany(TicketResolution::class, 'ticket_id')
            ->orderByDesc('resolution_no');
    }

    public function latestResolution(): HasOne
    {
        return $this->hasOne(TicketResolution::class, 'ticket_id')
            ->latestOfMany('resolution_no');
    }
}
