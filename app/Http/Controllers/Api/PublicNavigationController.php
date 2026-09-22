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
            $rootItems = NavbarItem::root()
                ->active()
                ->orderBy('display_order')
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
                    'brand',
                    'activeChildren' => function ($q) {
                        $q->orderBy('display_order')->with([
                            'category',
                            'subcategory',
                            'brand',
                            'activeChildren' => function ($subQ) {
                                $subQ->orderBy('display_order')->with(['category', 'subcategory', 'brand']);
                            }
                        ]);
                    }
                ])
                ->get();

            return $rootItems->map(fn($item) => $this->formatItem($item))->values()->all();
        });

        return response()->json($items);
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
            'children' => $item->activeChildren ? $item->activeChildren->map(fn($child) => $this->formatItem($child))->values()->toArray() : [],
        ];

        if ($item->type === 'category' && $item->category) {
            $data['category_id'] = $item->category->id;
            $data['category_slug'] = $item->category->slug;
            $data['category_name'] = $item->category->name;
            
            // Attached brands for mega menu
            $data['brands'] = $item->category->navbarBrands->map(function ($brand) {
                return [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                    'logo' => $brand->logo,
                    'is_featured' => (bool) $brand->is_featured,
                ];
            })->values()->toArray();

            // Direct subcategories
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
