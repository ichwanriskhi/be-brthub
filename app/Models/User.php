<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'auth_service_uuid',
        'full_name',
        'email',
        'phone_number',
        'address',
        'is_active',
        'source',
        'last_synced_at',
        'profile_completed_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
        'profile_completed_at' => 'datetime',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id')
            ->withPivot('assigned_at', 'expires_at', 'is_active', 'assigned_by_user_id')
            ->withTimestamps()
            ->wherePivot('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('name', $role)->exists();
    }

    public function getActiveRoles(): array
    {
        return $this->roles()->pluck('name')->toArray();
    }

    public function employeeProfile()
    {
        return $this->hasOne(EmployeeProfile::class, 'user_id');
    }

    public function customer()
    {
        return $this->hasOne(Customer::class, 'user_id');
    }

    public function isEmployee(): bool
    {
        return $this->employeeProfile()->exists();
    }
}
