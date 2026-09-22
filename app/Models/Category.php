<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'image',
        'icon',
        'badge',
        'is_featured',
        'display_order',
        'meta_title',
        'meta_description',
        'image_alt',
    ];

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('display_order');
    }

    public function childrenRecursive(): HasMany
    {
        return $this->children()->with(['childrenRecursive', 'products']);
    }

    /**
     * Recursively retrieve all descendant category IDs
     */
    protected static function booted(): void
    {
        static::saved(function () {
            \Illuminate\Support\Facades\Cache::forget('storefront_header_navigation');
        });

        static::deleted(function () {
            \Illuminate\Support\Facades\Cache::forget('storefront_header_navigation');
        });
    }

    public function getAllChildrenIds(): array
    {
        $ids = [];
        $children = $this->relationLoaded('children') ? $this->children : $this->children()->get();
        foreach ($children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, $child->getAllChildrenIds());
        }
        return $ids;
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function subcategoryProducts(): HasMany
    {
        return $this->hasMany(Product::class, 'subcategory_id');
    }

    public function brands()
    {
        return $this->belongsToMany(Brand::class, 'category_brand')
            ->withPivot(['display_order', 'is_in_navbar', 'is_featured'])
            ->orderByPivot('display_order')
            ->withTimestamps();
    }

    public function navbarBrands()
    {
        return $this->belongsToMany(Brand::class, 'category_brand')
            ->wherePivot('is_in_navbar', true)
            ->withPivot(['display_order', 'is_in_navbar', 'is_featured'])
            ->orderByPivot('display_order')
            ->withTimestamps();
    }
}

