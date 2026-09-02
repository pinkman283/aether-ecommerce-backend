<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminBrandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'brands.manage', 'products.view', 'products.manage');

        $brands = Brand::withCount('products')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return response()->json($brands);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'brands.manage', 'products.view', 'products.manage');

        $brand = Brand::withCount('products')
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
            'brand' => $brand->loadCount('products'),
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
            'brand' => $brand->loadCount('products'),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'brands.manage', 'products.manage');

        $brand = Brand::withCount('products')->findOrFail($id);

        if ($brand->products_count > 0) {
            return response()->json([
                'message' => "Cannot delete brand '{$brand->name}'. There are {$brand->products_count} hardware products associated with it. Reassign or delete those products first.",
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
            $brand = Brand::withCount('products')->find($id);
            if (!$brand) continue;

            if ($brand->products_count > 0) {
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
            $message .= " {$skippedCount} brands with assigned products were skipped.";
        }

        return response()->json([
            'message' => $message,
            'deleted_count' => $deletedCount,
            'skipped_count' => $skippedCount,
        ]);
    }
}
