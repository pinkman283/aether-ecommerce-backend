<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NavbarItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class PublicNavigationController extends Controller
{
    public function header(): JsonResponse
    {
        $items = Cache::remember('storefront_header_navigation', 86400, function () {
            // Bounded eager loading query - fetches all active items without N+1
            $allItems = NavbarItem::active()
                ->with([
                    'category' => function ($q) {
                        $q->with([
                            'navbarBrands' => function ($bq) {
                                $bq->select('brands.id', 'brands.name', 'brands.slug', 'brands.logo', 'brands.is_featured')
                                   ->orderBy('category_brand.display_order');
                            },
                            'children' => function ($cq) {
                                $cq->orderBy('display_order')->orderBy('name');
                            }
                        ]);
                    },
                    'subcategory',
                    'brand'
                ])
                ->orderBy('display_order')
                ->get();

            // Filter out invalid items (e.g. deleted targets, invalid category associations)
            $validItems = $allItems->filter(function ($item) {
                return (bool) $item->is_valid;
            });

            // Index valid items by ID to prune orphaned children whose parents are invalid or inactive
            $validIds = $validItems->pluck('id')->flip()->all();

            // Group by parent_id for O(N) tree building
            $grouped = [];
            foreach ($validItems as $item) {
                // If parent_id is set but parent is not valid/active, prune this branch
                if ($item->parent_id !== null && !isset($validIds[$item->parent_id])) {
                    continue;
                }
                $parentId = $item->parent_id ?? 0;
                $grouped[$parentId][] = $item;
            }

            return $this->buildTree($grouped, 0);
        });

        return response()->json($items);
    }

    /**
     * Build generic recursive navigation tree in O(N) memory time.
     * Supports arbitrary nesting depth while preventing runaway recursion.
     */
    private function buildTree(array &$grouped, int $parentId, int $depth = 0): array
    {
        // Guard against pathological trees
        if ($depth > 12 || !isset($grouped[$parentId])) {
            return [];
        }

        $branch = [];
        foreach ($grouped[$parentId] as $item) {
            $formatted = $this->formatItem($item);
            $formatted['children'] = $this->buildTree($grouped, $item->id, $depth + 1);
            $branch[] = $formatted;
        }

        return $branch;
    }

    private function formatItem(NavbarItem $item): array
    {
        $data = [
            'id' => $item->id,
            'parent_id' => $item->parent_id,
            'title' => $item->title,
            'type' => $item->type,
            'url' => $item->computed_url,
            'icon' => $item->icon,
            'badge' => $item->badge,
            'badge_color' => $item->badge_color,
            'open_in_new_tab' => (bool) $item->open_in_new_tab,
            'mega_menu_type' => $item->mega_menu_type,
            'display_order' => $item->display_order,
            'children' => [],
        ];

        if ($item->type === 'category' && $item->category) {
            $data['category_id'] = $item->category->id;
            $data['category_slug'] = $item->category->slug;
            $data['category_name'] = $item->category->name;

            // Attached brands for mega menu if loaded
            if ($item->category->relationLoaded('navbarBrands')) {
                $data['brands'] = $item->category->navbarBrands->map(function ($brand) {
                    return [
                        'id' => $brand->id,
                        'name' => $brand->name,
                        'slug' => $brand->slug,
                        'logo' => $brand->logo,
                        'is_featured' => (bool) $brand->is_featured,
                    ];
                })->values()->toArray();
            }

            // Direct catalog subcategories
            if ($item->category->relationLoaded('children')) {
                $data['subcategories'] = $item->category->children->map(function ($sub) use ($item) {
                    return [
                        'id' => $sub->id,
                        'name' => $sub->name,
                        'slug' => $sub->slug,
                        'url' => '/category/' . $item->category->slug . '/' . $sub->slug,
                        'icon' => $sub->icon,
                        'badge' => $sub->badge,
                    ];
                })->values()->toArray();
            }
        } elseif ($item->type === 'subcategory' && $item->subcategory) {
            $data['subcategory_id'] = $item->subcategory->id;
            $data['subcategory_slug'] = $item->subcategory->slug;
            $data['category_id'] = $item->category_id;
            $data['category_slug'] = $item->category?->slug;
        } elseif ($item->type === 'brand' && $item->brand) {
            $data['brand_id'] = $item->brand->id;
            $data['brand_slug'] = $item->brand->slug;
            $data['brand_name'] = $item->brand->name;
            $data['brand_logo'] = $item->brand->logo;
        }

        return $data;
    }
}
