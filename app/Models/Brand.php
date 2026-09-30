<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Brand extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'logo',
        'website',
        'is_featured',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function () {
            DB::afterCommit(function () {
                Cache::forget('storefront_header_navigation');
            });
        });

        static::deleted(function () {
            DB::afterCommit(function () {
                Cache::forget('storefront_header_navigation');
            });
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'brand_id');
    }

    public function productsLegacy(): HasMany
    {
        return $this->hasMany(Product::class, 'brand', 'name');
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_brand')
            ->withPivot(['display_order', 'is_in_navbar', 'is_featured'])
            ->withTimestamps();
    }
}
