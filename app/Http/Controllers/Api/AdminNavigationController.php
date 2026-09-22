<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\NavbarItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminNavigationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'online_store.manage', 'categories.manage', 'settings.manage');

        $items = NavbarItem::with(['category', 'subcategory', 'brand', 'parent'])
            ->orderBy('parent_id')
            ->orderBy('display_order')
            ->get();

        return response()->json($items);
    }

    public function tree(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'online_store.manage', 'categories.manage', 'settings.manage');

        $items = NavbarItem::root()
            ->with([
                'category',
                'subcategory',
                'brand',
                'children' => function ($q) {
                    $q->orderBy('display_order')->with([
                        'category',
                        'subcategory',
                        'brand',
                        'children' => function ($subQ) {
                            $subQ->orderBy('display_order')->with(['category', 'subcategory', 'brand']);
                        }
                    ]);
                }
            ])
            ->orderBy('display_order')
            ->get();

        return response()->json($items);
    }

    public function options(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'online_store.manage', 'categories.manage', 'settings.manage');

        $rootCategories = Category::whereNull('parent_id')
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $subcategories = Category::whereNotNull('parent_id')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'parent_id']);

        $brands = Brand::orderBy('name')->get(['id', 'name', 'slug', 'logo']);

        $pages = [];
        if (Schema::hasTable('cms_pages')) {
            $pages = DB::table('cms_pages')
                ->where('is_active', true)
                ->orderBy('title')
                ->get(['id', 'title', 'slug', 'slug as url']);
        }

        $potentialParents = NavbarItem::whereNull('parent_id')
            ->orderBy('display_order')
            ->get(['id', 'title', 'type']);

        return response()->json([
            'root_categories' => $rootCategories,
            'subcategories' => $subcategories,
            'brands' => $brands,
            'pages' => $pages,
            'potential_parents' => $potentialParents,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'online_store.manage', 'categories.manage', 'settings.manage');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:category,subcategory,brand,custom,dropdown_group',
            'parent_id' => 'nullable|integer|exists:navbar_items,id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'subcategory_id' => 'nullable|integer|exists:categories,id',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'url' => 'nullable|string|max:500',
            'icon' => 'nullable|string|max:100',
            'badge' => 'nullable|string|max:50',
            'badge_color' => 'nullable|string|max:50',
            'display_order' => 'nullable|integer',
            'is_active' => 'boolean',
            'open_in_new_tab' => 'boolean',
            'mega_menu_type' => 'nullable|in:none,category_brand_grid,columns,standard_dropdown',
        ]);

        if (!isset($validated['display_order'])) {
            $maxOrder = NavbarItem::where('parent_id', $validated['parent_id'] ?? null)->max('display_order');
            $validated['display_order'] = is_null($maxOrder) ? 0 : $maxOrder + 1;
        }

        if (!isset($validated['mega_menu_type'])) {
            $validated['mega_menu_type'] = 'none';
        }

        $item = NavbarItem::create($validated);
        Cache::forget('storefront_header_navigation');

        AuditLog::log(
            $request->user(),
            'navbar.created',
            'NavbarItem',
            $item->id,
            "Created navigation item '{$item->title}'"
        );

        return response()->json([
            'message' => 'Navigation item created successfully',
            'item' => $item->load(['category', 'subcategory', 'brand', 'parent']),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'online_store.manage', 'categories.manage', 'settings.manage');

        $item = NavbarItem::with(['category', 'subcategory', 'brand', 'parent', 'children'])->findOrFail($id);
        return response()->json($item);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'online_store.manage', 'categories.manage', 'settings.manage');

        $item = NavbarItem::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:category,subcategory,brand,custom,dropdown_group',
            'parent_id' => 'nullable|integer|exists:navbar_items,id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'subcategory_id' => 'nullable|integer|exists:categories,id',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'url' => 'nullable|string|max:500',
            'icon' => 'nullable|string|max:100',
            'badge' => 'nullable|string|max:50',
            'badge_color' => 'nullable|string|max:50',
            'display_order' => 'nullable|integer',
            'is_active' => 'boolean',
            'open_in_new_tab' => 'boolean',
            'mega_menu_type' => 'nullable|in:none,category_brand_grid,columns,standard_dropdown',
        ]);

        if (array_key_exists('parent_id', $validated) && $validated['parent_id'] == $item->id) {
            return response()->json(['message' => 'An item cannot be its own parent.'], 422);
        }

        $item->update($validated);
        Cache::forget('storefront_header_navigation');

        AuditLog::log(
            $request->user(),
            'navbar.updated',
            'NavbarItem',
            $item->id,
            "Updated navigation item '{$item->title}'"
        );

        return response()->json([
            'message' => 'Navigation item updated successfully',
            'item' => $item->load(['category', 'subcategory', 'brand', 'parent']),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'online_store.manage', 'categories.manage', 'settings.manage');

        $item = NavbarItem::findOrFail($id);
        $title = $item->title;

        $item->delete();
        Cache::forget('storefront_header_navigation');

        AuditLog::log(
            $request->user(),
            'navbar.deleted',
            'NavbarItem',
            $id,
            "Deleted navigation item '{$title}'"
        );

        return response()->json([
            'message' => 'Navigation item deleted successfully',
        ]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'online_store.manage', 'categories.manage', 'settings.manage');

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|integer|exists:navbar_items,id',
            'items.*.display_order' => 'required|integer',
            'items.*.parent_id' => 'nullable|integer|exists:navbar_items,id',
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['items'] as $itemData) {
                NavbarItem::where('id', $itemData['id'])->update([
                    'display_order' => $itemData['display_order'],
                    'parent_id' => $itemData['parent_id'] ?? null,
                    'updated_at' => now(),
                ]);
            }
        });

        Cache::forget('storefront_header_navigation');

        return response()->json([
            'message' => 'Navigation order updated successfully',
        ]);
    }

    public function getCategoryBrands(Request $request, int $categoryId): JsonResponse
    {
        $this->checkPermission($request, 'online_store.manage', 'categories.manage');

        $category = Category::findOrFail($categoryId);
        $linkedBrands = DB::table('category_brand')
            ->join('brands', 'category_brand.brand_id', '=', 'brands.id')
            ->where('category_brand.category_id', $categoryId)
            ->select('brands.id', 'brands.name', 'brands.slug', 'category_brand.display_order', 'category_brand.is_in_navbar', 'category_brand.is_featured')
            ->orderBy('category_brand.display_order')
            ->get();

        $allBrands = Brand::orderBy('name')->get(['id', 'name', 'slug']);

        return response()->json([
            'category' => $category->only(['id', 'name', 'slug']),
            'linked_brands' => $linkedBrands,
            'all_brands' => $allBrands,
        ]);
    }

    public function updateCategoryBrands(Request $request, int $categoryId): JsonResponse
    {
        $this->checkPermission($request, 'online_store.manage', 'categories.manage');

        $request->validate([
            'brands' => 'present|array',
            'brands.*.brand_id' => 'required|integer|exists:brands,id',
            'brands.*.display_order' => 'nullable|integer',
            'brands.*.is_in_navbar' => 'boolean',
            'brands.*.is_featured' => 'boolean',
        ]);

        $category = Category::findOrFail($categoryId);

        DB::transaction(function () use ($categoryId, $request) {
            DB::table('category_brand')->where('category_id', $categoryId)->delete();

            $insertData = [];
            foreach ($request->input('brands') as $idx => $b) {
                $insertData[] = [
                    'category_id' => $categoryId,
                    'brand_id' => $b['brand_id'],
                    'display_order' => $b['display_order'] ?? $idx,
                    'is_in_navbar' => $b['is_in_navbar'] ?? true,
                    'is_featured' => $b['is_featured'] ?? false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (!empty($insertData)) {
                DB::table('category_brand')->insert($insertData);
            }
        });

        Cache::forget('storefront_header_navigation');

        return response()->json([
            'message' => 'Category brands updated successfully',
        ]);
    }
}
