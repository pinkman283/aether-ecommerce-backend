<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        'is_valid',
        'invalid_reason',
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

    /**
     * Determine if this navigation target is currently valid.
     */
    public function getIsValidAttribute(): bool
    {
        switch ($this->type) {
            case 'category':
                return !empty($this->category_id) && $this->category !== null;

            case 'subcategory':
                if (empty($this->subcategory_id) || $this->subcategory === null) {
                    return false;
                }
                // If a parent category is linked, verify subcategory catalog consistency
                if (!empty($this->category_id) && (int) $this->subcategory->parent_id !== (int) $this->category_id) {
                    return false;
                }
                return true;

            case 'brand':
                return !empty($this->brand_id) && $this->brand !== null;

            case 'custom':
                return !empty(trim((string) $this->url));

            case 'dropdown_group':
                return true;

            default:
                return false;
        }
    }

    /**
     * Provide an actionable reason if the navigation target is broken.
     */
    public function getInvalidReasonAttribute(): ?string
    {
        switch ($this->type) {
            case 'category':
                if (empty($this->category_id) || $this->category === null) {
                    return 'Referenced category was deleted or not selected.';
                }
                return null;

            case 'subcategory':
                if (empty($this->subcategory_id) || $this->subcategory === null) {
                    return 'Referenced subcategory was deleted or not selected.';
                }
                if (!empty($this->category_id) && (int) $this->subcategory->parent_id !== (int) $this->category_id) {
                    return 'Subcategory does not belong to the selected parent category in the catalog.';
                }
                return null;

            case 'brand':
                if (empty($this->brand_id) || $this->brand === null) {
                    return 'Referenced brand was deleted or not selected.';
                }
                return null;

            case 'custom':
                if (empty(trim((string) $this->url))) {
                    return 'Custom URL is empty.';
                }
                return null;

            case 'dropdown_group':
                return null;

            default:
                return 'Unknown item type.';
        }
    }

    /**
     * Compute safe URL. Returns null if invalid (do not render as # link on storefront).
     */
    public function getComputedUrlAttribute(): ?string
    {
        if (!$this->is_valid && $this->type !== 'dropdown_group') {
            return null;
        }

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

        return null;
    }

    /**
     * Retrieve all descendant IDs to prevent cyclical parenting.
     */
    public function getAllDescendantIds(): array
    {
        $descendants = [];
        $directChildren = static::where('parent_id', $this->id)->pluck('id')->all();

        foreach ($directChildren as $childId) {
            $descendants[] = $childId;
            $child = static::find($childId);
            if ($child) {
                $descendants = array_merge($descendants, $child->getAllDescendantIds());
            }
        }

        return array_values(array_unique($descendants));
    }
}
