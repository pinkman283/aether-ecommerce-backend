<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'promotion_type',
        'discount_type',
        'discount_value',
        'max_discount_amount',
        'bxgy_buy_quantity',
        'bxgy_get_quantity',
        'bxgy_reward_discount_percent',
        'bxgy_max_applications',
        'applies_to',
        'status',
        'min_order_amount',
        'max_order_amount',
        'min_quantity',
        'max_quantity',
        'customer_eligibility',
        'payment_methods',
        'shipping_methods',
        'starts_at',
        'expires_at',
        'claim_deadline',
        'claim_validity_days',
        'total_usage_limit',
        'total_used_count',
        'per_customer_usage_limit',
        'total_claim_limit',
        'total_claimed_count',
        'is_stackable',
        'can_combine_with_free_shipping',
        'can_combine_with_order_discounts',
        'can_combine_with_product_discounts',
        'priority',
        'banner_image',
        'thumbnail_image',
        'badge_text',
        'cta_text',
        'cta_destination',
        'is_featured',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'bxgy_buy_quantity' => 'integer',
            'bxgy_get_quantity' => 'integer',
            'bxgy_reward_discount_percent' => 'decimal:2',
            'bxgy_max_applications' => 'integer',
            'min_order_amount' => 'decimal:2',
            'max_order_amount' => 'decimal:2',
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
            'payment_methods' => 'array',
            'shipping_methods' => 'array',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'claim_deadline' => 'datetime',
            'claim_validity_days' => 'integer',
            'total_usage_limit' => 'integer',
            'total_used_count' => 'integer',
            'per_customer_usage_limit' => 'integer',
            'total_claim_limit' => 'integer',
            'total_claimed_count' => 'integer',
            'is_stackable' => 'boolean',
            'can_combine_with_free_shipping' => 'boolean',
            'can_combine_with_order_discounts' => 'boolean',
            'can_combine_with_product_discounts' => 'boolean',
            'priority' => 'integer',
            'is_featured' => 'boolean',
        ];
    }

    public function codes(): HasMany
    {
        return $this->hasMany(PromotionCode::class);
    }

    public function productTargets(): HasMany
    {
        return $this->hasMany(PromotionProductTarget::class);
    }

    public function customerRestrictions(): HasMany
    {
        return $this->hasMany(PromotionCustomerRestriction::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(PromotionClaim::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Check if the promotion is currently active based on status and dates.
     */
    public function isScheduleActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $now = now();

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->total_usage_limit !== null && $this->total_used_count >= $this->total_usage_limit) {
            return false;
        }

        return true;
    }

    /**
     * Check if a claim can still be made.
     */
    public function isClaimable(): bool
    {
        if ($this->promotion_type !== 'claimable_coupon') {
            return false;
        }

        if (!$this->isScheduleActive()) {
            return false;
        }

        if ($this->claim_deadline && $this->claim_deadline->isPast()) {
            return false;
        }

        if ($this->total_claim_limit !== null && $this->total_claimed_count >= $this->total_claim_limit) {
            return false;
        }

        return true;
    }
}
