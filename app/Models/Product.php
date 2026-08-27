<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'brand',
        'sku',
        'short_description',
        'description',
        'price',
        'compare_at_price',
        'stock_quantity',
        'is_featured',
        'is_new_arrival',
        'is_best_seller',
        'is_active',
        'rating_average',
        'review_count',
        'tags',
        'specifications',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'compare_at_price' => 'float',
            'stock_quantity' => 'integer',
            'is_featured' => 'boolean',
            'is_new_arrival' => 'boolean',
            'is_best_seller' => 'boolean',
            'is_active' => 'boolean',
            'rating_average' => 'float',
            'review_count' => 'integer',
            'tags' => 'array',
            'specifications' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('display_order');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class)->where('is_approved', true)->latest();
    }

    public function vendorProducts(): HasMany
    {
        return $this->hasMany(VendorProduct::class);
    }

    public function costLayers(): HasMany
    {
        return $this->hasMany(InventoryCostLayer::class)->orderBy('created_at', 'asc');
    }

    public function activeCostLayers(): HasMany
    {
        return $this->hasMany(InventoryCostLayer::class)->where('is_depleted', false)->orderBy('created_at', 'asc');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class)->latest();
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeNewArrivals(Builder $query): Builder
    {
        return $query->where('is_new_arrival', true);
    }

    public function scopeBestSellers(Builder $query): Builder
    {
        return $query->where('is_best_seller', true);
    }
}
