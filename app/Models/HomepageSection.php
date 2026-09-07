<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomepageSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'subtitle',
        'badge_text',
        'badge_icon',
        'is_active',
        'sort_order',
        'product_count',
        'view_all_label',
        'view_all_url',
        'view_all_type',
        'view_all_category_id',
        'view_all_brand_id',
        'has_tabs',
        'tabs',
        'source_type',
        'category_id',
        'brand_id',
        'sort_by',
        'product_ids',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'has_tabs' => 'boolean',
        'sort_order' => 'integer',
        'product_count' => 'integer',
        'view_all_category_id' => 'integer',
        'view_all_brand_id' => 'integer',
        'tabs' => 'array',
        'product_ids' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function viewAllCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'view_all_category_id');
    }

    public function viewAllBrand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'view_all_brand_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc')->orderBy('id', 'asc');
    }
}
