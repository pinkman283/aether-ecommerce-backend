<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerIpLog extends Model
{
    use HasFactory;

    protected $table = 'customer_ip_logs';

    protected $fillable = [
        'user_id',
        'ip_address',
        'action',
        'order_id',
        'user_agent',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public static function record(
        ?User $user,
        string $ip,
        string $action = 'order_created',
        ?int $orderId = null,
        ?string $userAgent = null
    ): self {
        return self::create([
            'user_id' => $user?->id,
            'ip_address' => $ip,
            'action' => $action,
            'order_id' => $orderId,
            'user_agent' => substr((string) ($userAgent ?: request()->userAgent()), 0, 500),
        ]);
    }
}
