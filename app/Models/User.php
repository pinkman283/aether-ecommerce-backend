<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'customer_type',
        'permissions',
        'status',
        'risk_level',
        'risk_score',
        'risk_reasons',
        'internal_notes',
        'suspended_until',
        'suspension_reason',
        'phone',
        'avatar',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'suspended_until' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
            'risk_reasons' => 'array',
            'risk_score' => 'integer',
        ];
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'super_admin']);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isStaffOrAdmin(): bool
    {
        return in_array($this->role, ['staff', 'admin', 'super_admin']);
    }

    public function isCustomer(): bool
    {
        return $this->role === 'customer';
    }

    public function isGuest(): bool
    {
        return $this->customer_type === 'guest';
    }

    public function isBlocked(): bool
    {
        return $this->status === 'blocked';
    }

    public function isUnderReview(): bool
    {
        return $this->status === 'review';
    }

    public function isSuspended(): bool
    {
        if ($this->status !== 'suspended') {
            return false;
        }

        // If timed suspension duration has expired, auto-reactivate the account
        if ($this->suspended_until && now()->greaterThan($this->suspended_until)) {
            $this->update([
                'status' => 'active',
                'suspended_until' => null,
                'suspension_reason' => null,
            ]);
            return false;
        }

        return true;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($this->isAdmin()) {
            return true;
        }

        return is_array($this->permissions) && in_array($permission, $this->permissions);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function customerIpLogs(): HasMany
    {
        return $this->hasMany(CustomerIpLog::class);
    }
}
