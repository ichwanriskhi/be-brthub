<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketAssignment extends Model
{
    protected $table = 'ticket_assignments';

    protected $fillable = [
        'ticket_id',
        'assignment_type',
        'assigned_to_employee_id',
        'assigned_by_employee_id',
        'assigned_to_department_id',
        'assigned_at',
        'unassigned_at',
        'is_active',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'unassigned_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function assignedToEmployee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'assigned_to_employee_id');
    }

    public function assignedByEmployee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'assigned_by_employee_id');
    }

    public function assignedToDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'assigned_to_department_id');
    }
}