<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionRedemption extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'promotion_id',
        'promotion_code_id',
        'promotion_claim_id',
        'order_id',
        'user_id',
        'customer_email',
        'code_used',
        'promotion_type',
        'discount_type',
        'discount_amount',
        'order_subtotal',
        'order_total',
        'status',
        'reversed_at',
        'reversal_reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'discount_amount' => 'decimal:2',
            'order_subtotal' => 'decimal:2',
            'order_total' => 'decimal:2',
            'reversed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'completed');
    }

    public function reverse(string $reason = 'Order cancelled'): bool
    {
        $this->status = 'reversed';
        $this->reversed_at = now();
        $this->reversal_reason = $reason;
        return $this->save();
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function promotionCode(): BelongsTo
    {
        return $this->belongsTo(PromotionCode::class);
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(PromotionClaim::class, 'promotion_claim_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
