<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminBrandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'brands.manage', 'products.view', 'products.manage');

        $brands = Brand::withCount(['products', 'categories'])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return response()->json($brands);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'brands.manage', 'products.view', 'products.manage');

        $brand = Brand::withCount(['products', 'categories'])
            ->with(['products' => function ($q) {
                $q->select('id', 'name', 'slug', 'brand', 'price', 'stock_quantity', 'rating_average')
                  ->with('primaryImage')
                  ->take(20);
            }])
            ->findOrFail($id);

        return response()->json($brand);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'brands.manage', 'products.manage');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'logo' => 'nullable|string',
            'website' => 'nullable|string|max:255',
            'is_featured' => 'boolean',
            'display_order' => 'integer',
        ]);

        $slug = Str::slug($validated['name']);
        if (Brand::where('slug', $slug)->exists()) {
            $slug .= '-' . Str::random(4);
        }

        $brand = Brand::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'logo' => $validated['logo'] ?? null,
            'website' => $validated['website'] ?? null,
            'is_featured' => $validated['is_featured'] ?? false,
            'display_order' => $validated['display_order'] ?? 0,
        ]);

        AuditLog::log(
            $request->user(),
            'brand.created',
            'Brand',
            $brand->id,
            "Created brand '{$brand->name}'"
        );

        return response()->json([
            'message' => 'Brand created successfully',
            'brand' => $brand->loadCount(['products', 'categories']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'brands.manage', 'products.manage');

        $brand = Brand::findOrFail($id);
        $oldValues = $brand->toArray();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'logo' => 'nullable|string',
            'website' => 'nullable|string|max:255',
            'is_featured' => 'boolean',
            'display_order' => 'integer',
        ]);

        $brand->fill($validated);
        $wasDirty = $brand->isDirty();
        $brand->save();

        if ($wasDirty) {
            AuditLog::log(
                $request->user(),
                'brand.updated',
                'Brand',
                $brand->id,
                "Updated brand '{$brand->name}'",
                $oldValues,
                $brand->toArray()
            );
        }

        return response()->json([
            'message' => 'Brand updated successfully',
            'brand' => $brand->loadCount(['products', 'categories']),
        ]);
    }

    /**
     * Upload brand logo file to storage/app/public/brands
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'brands.manage', 'products.manage');

        $request->validate([
            'logo' => 'required|file|mimes:jpeg,png,jpg,gif,webp,avif|max:20480',
        ]);

        $file = $request->file('logo');
        $extension = $file->getClientOriginalExtension();
        $filename = 'brand_' . Str::random(20) . '.' . $extension;
        $path = $file->storeAs('brands', $filename, 'public');

        $url = Storage::disk('public')->url($path);

        return response()->json([
            'message' => 'Brand logo uploaded successfully',
            'logo_url' => $url,
            'image_url' => $url,
            'path' => $path,
            'filename' => $filename,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'brands.manage', 'products.manage');

        $brand = Brand::findOrFail($id);

        $conflicts = $this->getBrandConflicts($id);

        if (!empty($conflicts)) {
            $reasons = [];
            if (!empty($conflicts['products'])) {
                $reasons[] = "{$conflicts['products']} hardware product(s) assigned";
            }
            if (!empty($conflicts['categories'])) {
                $reasons[] = "{$conflicts['categories']} category relationship(s)";
            }
            if (!empty($conflicts['navigation'])) {
                $reasons[] = "{$conflicts['navigation']} header navigation item(s)";
            }
            if (!empty($conflicts['homepage_sections'])) {
                $reasons[] = "{$conflicts['homepage_sections']} homepage showcase section(s)";
            }

            return response()->json([
                'message' => "Cannot delete brand '{$brand->name}'. It is still in use: " . implode(', ', $reasons) . ". Reassign or remove these dependencies first.",
                'conflicts' => $conflicts,
            ], 422);
        }

        $name = $brand->name;
        $brand->delete();

        AuditLog::log(
            $request->user(),
            'brand.deleted',
            'Brand',
            $id,
            "Deleted brand '{$name}'"
        );

        return response()->json([
            'message' => "Brand '{$name}' deleted successfully",
        ]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'brands.manage', 'products.manage');

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:brands,id',
        ]);

        $deletedCount = 0;
        $skippedCount = 0;

        foreach ($validated['ids'] as $id) {
            $brand = Brand::find($id);
            if (!$brand) continue;

            $conflicts = $this->getBrandConflicts($id);

            if (!empty($conflicts)) {
                $skippedCount++;
                continue;
            }

            $name = $brand->name;
            $brand->delete();
            $deletedCount++;

            AuditLog::log(
                $request->user(),
                'brand.deleted',
                'Brand',
                $id,
                "Bulk deleted brand '{$name}'"
            );
        }

        $message = "Successfully deleted {$deletedCount} brand(s).";
        if ($skippedCount > 0) {
            $message .= " {$skippedCount} brand(s) with active dependencies were skipped.";
        }

        return response()->json([
            'message' => $message,
            'deleted_count' => $deletedCount,
            'skipped_count' => $skippedCount,
        ]);
    }

    /**
     * Inspect active dependencies that block brand deletion
     */
    protected function getBrandConflicts(int $brandId): array
    {
        $conflicts = [];

        // 1. Products assigned via brand_id
        $productCount = DB::table('products')->where('brand_id', $brandId)->count();
        if ($productCount > 0) {
            $conflicts['products'] = $productCount;
        }

        // 2. Category relationships via category_brand pivot
        $categoryCount = DB::table('category_brand')->where('brand_id', $brandId)->count();
        if ($categoryCount > 0) {
            $conflicts['categories'] = $categoryCount;
        }

        // 3. Navbar items referencing brand_id
        $navbarCount = DB::table('navbar_items')->where('brand_id', $brandId)->count();
        if ($navbarCount > 0) {
            $conflicts['navigation'] = $navbarCount;
        }

        // 4. Homepage sections referencing brand_id or view_all_brand_id
        $homepageCount = DB::table('homepage_sections')
            ->where('brand_id', $brandId)
            ->orWhere('view_all_brand_id', $brandId)
            ->count();
        if ($homepageCount > 0) {
            $conflicts['homepage_sections'] = $homepageCount;
        }

        return $conflicts;
    }
}
