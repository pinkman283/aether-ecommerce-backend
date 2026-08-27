<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockedIp extends Model
{
    use HasFactory;

    protected $table = 'blocked_ips';

    protected $fillable = [
        'ip_address',
        'status',
        'reason',
        'notes',
        'blocked_by_user_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by_user_id');
    }

    public function isCurrentlyActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->expires_at && now()->greaterThan($this->expires_at)) {
            // Block has expired, auto-update state
            $this->update(['status' => 'expired']);
            return false;
        }

        return true;
    }
}
