<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::with(['category', 'primaryImage', 'variants'])->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('stock_status')) {
            if ($request->input('stock_status') === 'in_stock') {
                $query->where('stock_quantity', '>', 0);
            } elseif ($request->input('stock_status') === 'low_stock') {
                $query->where('stock_quantity', '>', 0)->where('stock_quantity', '<=', 10);
            } elseif ($request->input('stock_status') === 'out_of_stock') {
                $query->where('stock_quantity', '<=', 0);
            }
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = (int) $request->input('per_page', 15);
        $products = $query->paginate($perPage);

        return response()->json($products);
    }

    public function show(int $id): JsonResponse
    {
        $product = Product::with(['category', 'images', 'variants', 'reviews'])->findOrFail($id);
        return response()->json($product);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'brand' => 'nullable|string|max:255',
            'price' => 'required|numeric|min:0',
            'compare_at_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'required|integer|min:0',
            'short_description' => 'nullable|string',
            'description' => 'required|string',
            'is_featured' => 'boolean',
            'is_new_arrival' => 'boolean',
            'is_best_seller' => 'boolean',
            'is_active' => 'boolean',
            'image_url' => 'nullable|string|url',
            'specifications' => 'nullable|array',
            'tags' => 'nullable|array',
        ]);

        $slug = Str::slug($validated['name']) . '-' . Str::random(4);
        $sku = 'PRD-' . strtoupper(Str::random(6));

        $product = Product::create([
            'category_id' => $validated['category_id'],
            'name' => $validated['name'],
            'slug' => $slug,
            'brand' => $validated['brand'] ?? 'AETHER Studio',
            'sku' => $sku,
            'price' => (float) $validated['price'],
            'compare_at_price' => isset($validated['compare_at_price']) ? (float) $validated['compare_at_price'] : null,
            'stock_quantity' => (int) $validated['stock_quantity'],
            'short_description' => $validated['short_description'] ?? null,
            'description' => $validated['description'],
            'is_featured' => $validated['is_featured'] ?? false,
            'is_new_arrival' => $validated['is_new_arrival'] ?? true,
            'is_best_seller' => $validated['is_best_seller'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
            'rating_average' => 5.0,
            'review_count' => 0,
            'specifications' => $validated['specifications'] ?? [],
            'tags' => $validated['tags'] ?? [],
        ]);

        if (!empty($validated['image_url'])) {
            $product->images()->create([
                'image_url' => $validated['image_url'],
                'alt_text' => $product->name,
                'is_primary' => true,
                'display_order' => 0,
            ]);
        }

        AuditLog::log(
            $request->user(),
            'product.created',
            'Product',
            $product->id,
            "Created hardware product '{$product->name}' (SKU: {$product->sku})",
            null,
            $product->toArray()
        );

        return response()->json([
            'message' => 'Hardware product created successfully',
            'product' => $product->load(['category', 'primaryImage', 'variants']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $oldValues = $product->toArray();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category_id' => 'sometimes|required|exists:categories,id',
            'brand' => 'nullable|string|max:255',
            'price' => 'sometimes|required|numeric|min:0',
            'compare_at_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'sometimes|required|integer|min:0',
            'short_description' => 'nullable|string',
            'description' => 'sometimes|required|string',
            'is_featured' => 'boolean',
            'is_new_arrival' => 'boolean',
            'is_best_seller' => 'boolean',
            'is_active' => 'boolean',
            'specifications' => 'nullable|array',
            'tags' => 'nullable|array',
        ]);

        $product->fill($validated);
        $wasDirty = $product->isDirty();
        $product->save();

        if ($wasDirty) {
            AuditLog::log(
                $request->user(),
                'product.updated',
                'Product',
                $product->id,
                "Updated hardware product '{$product->name}' (SKU: {$product->sku})",
                $oldValues,
                $product->toArray()
            );
        }

        return response()->json([
            'message' => 'Product updated successfully',
            'product' => $product->load(['category', 'primaryImage', 'variants']),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $name = $product->name;
        $sku = $product->sku;
        $oldValues = $product->toArray();

        $product->delete();

        AuditLog::log(
            $request->user(),
            'product.deleted',
            'Product',
            $id,
            "Deleted hardware product '{$name}' (SKU: {$sku})",
            $oldValues,
            null
        );

        return response()->json([
            'message' => "Product '{$name}' deleted successfully",
        ]);
    }
}
