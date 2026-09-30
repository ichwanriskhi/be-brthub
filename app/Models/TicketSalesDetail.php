<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketSalesDetail extends Model
{
    protected $table = 'ticket_sales_details';

    protected $guarded = ['id'];

    protected $casts = [
        'claimed_items' => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }
}
