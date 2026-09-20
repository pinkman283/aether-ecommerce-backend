<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Banner extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'subtitle',
        'eyebrow',
        'image_url',
        'mobile_image_url',
        'alt_text',
        'cta_text',
        'cta_link',
        'destination_type',
        'destination_id',
        'promotion_id',
        'placement',
        'badge',
        'discount_tag',
        'sort_order',
        'is_active',
        'starts_at',
        'expires_at',
        'clicks_count',
        'impressions_count',
    ];

    protected $appends = [
        'computed_link',
        'is_currently_visible',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'clicks_count' => 'integer',
            'impressions_count' => 'integer',
            'destination_id' => 'integer',
            'promotion_id' => 'integer',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class, 'promotion_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'destination_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'destination_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'destination_id');
    }

    /**
     * Compute authoritative frontend destination URL based on destination_type.
     */
    public function getComputedLinkAttribute(): string
    {
        if ($this->destination_type === 'product' && $this->product) {
            return '/products/' . $this->product->slug;
        }

        if ($this->destination_type === 'category' && $this->category) {
            return '/products?category=' . $this->category->slug;
        }

        if ($this->destination_type === 'brand' && $this->brand) {
            return '/products?brand=' . $this->brand->slug;
        }

        if ($this->destination_type === 'promotion' && $this->promotion) {
            return '/promotions/' . $this->promotion->slug;
        }

        if ($this->destination_type === 'collection' && $this->destination_id) {
            return '/products?collection=' . $this->destination_id;
        }

        if ($this->destination_type === 'page' && $this->cta_link) {
            return str_starts_with($this->cta_link, '/') ? $this->cta_link : '/' . $this->cta_link;
        }

        // Custom URL or default fallback
        return $this->cta_link ?: '/products';
    }

    /**
     * Check if banner is currently within its schedule window and active.
     */
    public function getIsCurrentlyVisibleAttribute(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $now = now();

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        // If banner is linked to a promotion, the promotion must also be active and within schedule
        if ($this->promotion_id) {
            if (!$this->relationLoaded('promotion')) {
                $this->load('promotion');
            }
            if (!$this->promotion || !$this->promotion->isScheduleActive()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Scope for banners eligible for public storefront rendering.
     */
    public function scopeActiveAndScheduled($query)
    {
        $now = now();
        return $query->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
            })
            ->where(function ($q) {
                $q->whereNull('promotion_id')
                    ->orWhereHas('promotion', function ($pq) {
                        $pq->activeSchedule();
                    });
            });
    }
}
