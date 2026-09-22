<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class NavbarItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'title',
        'type',
        'category_id',
        'subcategory_id',
        'brand_id',
        'url',
        'icon',
        'badge',
        'badge_color',
        'display_order',
        'is_active',
        'open_in_new_tab',
        'mega_menu_type',
    ];

    protected $appends = [
        'computed_url',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'open_in_new_tab' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function () {
            Cache::forget('storefront_header_navigation');
        });

        static::deleted(function () {
            Cache::forget('storefront_header_navigation');
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(NavbarItem::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(NavbarItem::class, 'parent_id')->orderBy('display_order');
    }

    public function activeChildren(): HasMany
    {
        return $this->hasMany(NavbarItem::class, 'parent_id')
            ->where('is_active', true)
            ->orderBy('display_order');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'subcategory_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }

    public function getComputedUrlAttribute(): string
    {
        if (!empty($this->url)) {
            return $this->url;
        }

        switch ($this->type) {
            case 'category':
                if ($this->category) {
                    return '/category/' . $this->category->slug;
                }
                break;
            case 'subcategory':
                if ($this->category && $this->subcategory) {
                    return '/category/' . $this->category->slug . '/' . $this->subcategory->slug;
                } elseif ($this->subcategory) {
                    return '/category/' . $this->subcategory->slug;
                }
                break;
            case 'brand':
                if ($this->brand) {
                    return '/brand/' . $this->brand->slug;
                }
                break;
            case 'dropdown_group':
                return '#';
        }

        return '#';
    }
}
