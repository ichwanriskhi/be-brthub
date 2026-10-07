<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketVehicleDetail extends Model
{
    protected $table = 'ticket_vehicle_details';

    protected $guarded = ['id'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }
}
