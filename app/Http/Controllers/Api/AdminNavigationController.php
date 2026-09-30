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

    /**
     * Return complete hierarchical tree for admin with arbitrary depth and validation status.
     */
    public function tree(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'online_store.manage', 'categories.manage', 'settings.manage');

        $allItems = NavbarItem::with(['category', 'subcategory', 'brand', 'parent'])
            ->orderBy('display_order')
            ->get();

        $grouped = [];
        foreach ($allItems as $item) {
            $pId = $item->parent_id ?? 0;
            $grouped[$pId][] = $item;
        }

        $tree = $this->buildAdminTree($grouped, 0);

        return response()->json($tree);
    }

    private function buildAdminTree(array &$grouped, int $parentId, int $depth = 0): array
    {
        if ($depth > 12 || !isset($grouped[$parentId])) {
            return [];
        }

        $branch = [];
        foreach ($grouped[$parentId] as $item) {
            $itemArray = $item->toArray();
            $itemArray['depth'] = $depth;
            $itemArray['children'] = $this->buildAdminTree($grouped, $item->id, $depth + 1);
            $branch[] = $itemArray;
        }

        return $branch;
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

        // Build potential parents list with breadcrumb paths so any node can be a parent
        $allItems = NavbarItem::orderBy('parent_id')->orderBy('display_order')->get(['id', 'parent_id', 'title', 'type']);
        $itemMap = $allItems->keyBy('id');

        $potentialParents = $allItems->map(function ($item) use ($itemMap) {
            $pathParts = [$item->title];
            $curr = $item;
            $depth = 0;

            while ($curr->parent_id && isset($itemMap[$curr->parent_id]) && $depth < 10) {
                $curr = $itemMap[$curr->parent_id];
                array_unshift($pathParts, $curr->title);
                $depth++;
            }

            return [
                'id' => $item->id,
                'title' => $item->title,
                'type' => $item->type,
                'depth' => $depth,
                'path' => implode('  ›  ', $pathParts),
            ];
        })->sortBy('path')->values()->all();

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

        // Validate catalog relationship consistency
        if ($validated['type'] === 'subcategory' && !empty($validated['subcategory_id']) && !empty($validated['category_id'])) {
            $sub = Category::find($validated['subcategory_id']);
            if ($sub && (int) $sub->parent_id !== (int) $validated['category_id']) {
                return response()->json([
                    'message' => 'The selected subcategory does not belong to the selected parent category in the catalog.',
                ], 422);
            }
        }

        if (!isset($validated['display_order'])) {
            $maxOrder = NavbarItem::where('parent_id', $validated['parent_id'] ?? null)->max('display_order');
            $validated['display_order'] = is_null($maxOrder) ? 0 : $maxOrder + 1;
        }

        if (!isset($validated['mega_menu_type'])) {
            $validated['mega_menu_type'] = 'none';
        }

        $item = DB::transaction(function () use ($validated, $request) {
            $created = NavbarItem::create($validated);

            AuditLog::log(
                $request->user(),
                'navbar.created',
                'NavbarItem',
                $created->id,
                "Created navigation item '{$created->title}'"
            );

            return $created;
        });

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

        // Cycle Protection: item cannot be its own parent
        if (array_key_exists('parent_id', $validated) && (int) $validated['parent_id'] === (int) $item->id) {
            return response()->json(['message' => 'An item cannot be its own parent.'], 422);
        }

        // Cycle Protection: item cannot be parented under any of its own descendants
        if (!empty($validated['parent_id'])) {
            $descendantIds = $item->getAllDescendantIds();
            if (in_array((int) $validated['parent_id'], $descendantIds, true)) {
                return response()->json([
                    'message' => 'Circular hierarchy detected: an item cannot be parented under one of its own descendants.',
                ], 422);
            }
        }

        // Catalog consistency check
        $effectiveType = $validated['type'] ?? $item->type;
        $effectiveSubId = array_key_exists('subcategory_id', $validated) ? $validated['subcategory_id'] : $item->subcategory_id;
        $effectiveCatId = array_key_exists('category_id', $validated) ? $validated['category_id'] : $item->category_id;

        if ($effectiveType === 'subcategory' && !empty($effectiveSubId) && !empty($effectiveCatId)) {
            $sub = Category::find($effectiveSubId);
            if ($sub && (int) $sub->parent_id !== (int) $effectiveCatId) {
                return response()->json([
                    'message' => 'The selected subcategory does not belong to the selected parent category in the catalog.',
                ], 422);
            }
        }

        DB::transaction(function () use ($item, $validated, $request) {
            $item->update($validated);

            AuditLog::log(
                $request->user(),
                'navbar.updated',
                'NavbarItem',
                $item->id,
                "Updated navigation item '{$item->title}'"
            );
        });

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

        DB::transaction(function () use ($item, $id, $title, $request) {
            $item->delete();

            AuditLog::log(
                $request->user(),
                'navbar.deleted',
                'NavbarItem',
                $id,
                "Deleted navigation item '{$title}'"
            );
        });

        return response()->json([
            'message' => 'Navigation item deleted successfully',
        ]);
    }

    /**
     * Duplicate a navigation item and all its descendants recursively.
     */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'online_store.manage', 'categories.manage', 'settings.manage');

        $original = NavbarItem::findOrFail($id);

        $duplicatedItem = DB::transaction(function () use ($original, $request) {
            return $this->recursivelyCloneNode($original, $original->parent_id, true, $request->user());
        });

        return response()->json([
            'message' => "Successfully duplicated '{$original->title}' and its hierarchy",
            'item' => $duplicatedItem->load(['category', 'subcategory', 'brand', 'parent']),
        ], 201);
    }

    private function recursivelyCloneNode(NavbarItem $node, ?int $newParentId, bool $isRootClone = false, $user = null): NavbarItem
    {
        $attributes = $node->toArray();
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at'], $attributes['computed_url'], $attributes['is_valid'], $attributes['invalid_reason'], $attributes['category'], $attributes['subcategory'], $attributes['brand'], $attributes['parent'], $attributes['children'], $attributes['active_children']);

        $attributes['parent_id'] = $newParentId;
        if ($isRootClone) {
            $attributes['title'] = $node->title . ' (Copy)';
            $maxOrder = NavbarItem::where('parent_id', $newParentId)->max('display_order');
            $attributes['display_order'] = is_null($maxOrder) ? 0 : $maxOrder + 1;
        }

        $clone = NavbarItem::create($attributes);

        if ($user) {
            AuditLog::log(
                $user,
                'navbar.duplicated',
                'NavbarItem',
                $clone->id,
                "Duplicated navigation item '{$clone->title}'"
            );
        }

        // Recursively clone all children
        $children = NavbarItem::where('parent_id', $node->id)->orderBy('display_order')->get();
        foreach ($children as $child) {
            $this->recursivelyCloneNode($child, $clone->id, false, $user);
        }

        return $clone;
    }

    /**
     * Atomic hierarchy reordering / nesting update.
     */
    public function reorder(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'online_store.manage', 'categories.manage', 'settings.manage');

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|integer|exists:navbar_items,id',
            'items.*.display_order' => 'required|integer',
            'items.*.parent_id' => 'nullable|integer|exists:navbar_items,id',
        ]);

        // Validate cycle prevention across the entire reorder payload
        $idToParent = [];
        foreach ($validated['items'] as $itemData) {
            $idToParent[$itemData['id']] = $itemData['parent_id'] ?? null;
        }

        foreach ($idToParent as $id => $parentId) {
            if ($parentId === $id) {
                return response()->json(['message' => 'An item cannot be its own parent.'], 422);
            }

            // Trace parent chain up to detect loop
            $visited = [$id => true];
            $curr = $parentId;
            while ($curr !== null) {
                if (isset($visited[$curr])) {
                    return response()->json([
                        'message' => 'Circular navigation hierarchy detected. Operation cancelled.',
                    ], 422);
                }
                $visited[$curr] = true;
                $curr = $idToParent[$curr] ?? NavbarItem::where('id', $curr)->value('parent_id');
            }
        }

        DB::transaction(function () use ($validated) {
            foreach ($validated['items'] as $itemData) {
                NavbarItem::where('id', $itemData['id'])->update([
                    'display_order' => $itemData['display_order'],
                    'parent_id' => $itemData['parent_id'] ?? null,
                    'updated_at' => now(),
                ]);
            }
        });

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

        return response()->json([
            'message' => 'Category brands updated successfully',
        ]);
    }
}
