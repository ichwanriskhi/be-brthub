<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WansisReport extends Model
{
    protected $table = 'wansis_reports';

    protected $guarded = ['id'];

    protected $casts = [
        'payload_json' => 'array',
        'response_json' => 'array',
        'sent_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }
}
