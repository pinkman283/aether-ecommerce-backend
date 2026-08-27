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
        $this->checkPermission($request, 'products.view', 'products.manage');

        $query = Product::with(['category', 'primaryImage', 'images', 'variants'])->latest();

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

    public function show(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'products.view', 'products.manage');

        $product = Product::with(['category', 'images', 'variants', 'reviews'])->findOrFail($id);
        return response()->json($product);
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'products.manage');

        $request->validate([
            'image' => 'required|file|image|mimes:jpeg,png,jpg,webp,gif,svg,avif|max:10240',
        ]);

        $file = $request->file('image');
        $filename = 'prod_' . Str::random(16) . '_' . time() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('products', $filename, 'public');
        $fullUrl = url('storage/' . $path);

        return response()->json([
            'message' => 'Product image uploaded successfully',
            'image_url' => $fullUrl,
            'path' => $path,
            'filename' => $filename,
        ], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'products.manage');

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
            'image_url' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*.image_url' => 'required_with:images|string',
            'images.*.is_primary' => 'nullable|boolean',
            'images.*.alt_text' => 'nullable|string|max:255',
            'images.*.display_order' => 'nullable|integer',
            'specifications' => 'nullable|array',
            'tags' => 'nullable|array',
        ]);

        // Normalize images list
        $imageItems = [];
        if (!empty($validated['images']) && is_array($validated['images'])) {
            $imageItems = array_values(array_filter($validated['images'], function ($img) {
                return !empty($img['image_url']);
            }));
        } elseif (!empty($validated['image_url'])) {
            $imageItems = [
                [
                    'image_url' => $validated['image_url'],
                    'is_primary' => true,
                    'alt_text' => $validated['name'],
                    'display_order' => 0,
                ]
            ];
        }

        // Mandatory check: At least 1 image
        if (empty($imageItems)) {
            return response()->json([
                'message' => 'Validation error: At least 1 product image is mandatory. Please provide an image URL or upload an image from your device.',
                'errors' => ['images' => ['At least 1 product image is mandatory.']]
            ], 422);
        }

        // Limit check: Maximum 5 images
        if (count($imageItems) > 5) {
            return response()->json([
                'message' => 'Validation error: A product cannot have more than 5 images.',
                'errors' => ['images' => ['Maximum limit is 5 images per product.']]
            ], 422);
        }

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

        $hasPrimary = false;
        foreach ($imageItems as $idx => $img) {
            $isPrimary = !empty($img['is_primary']);
            if ($isPrimary) {
                if ($hasPrimary) {
                    $isPrimary = false;
                } else {
                    $hasPrimary = true;
                }
            }
            $imageItems[$idx]['_calculated_primary'] = $isPrimary;
        }

        if (!$hasPrimary && count($imageItems) > 0) {
            $imageItems[0]['_calculated_primary'] = true;
        }

        foreach ($imageItems as $idx => $img) {
            $product->images()->create([
                'image_url' => $img['image_url'],
                'alt_text' => $img['alt_text'] ?? $product->name,
                'is_primary' => $imageItems[$idx]['_calculated_primary'],
                'display_order' => $img['display_order'] ?? $idx,
            ]);
        }

        AuditLog::log(
            $request->user(),
            'product.created',
            'Product',
            $product->id,
            "Created hardware product '{$product->name}' (SKU: {$product->sku}) with " . count($imageItems) . " image(s)",
            null,
            $product->toArray()
        );

        return response()->json([
            'message' => 'Hardware product created successfully',
            'product' => $product->load(['category', 'primaryImage', 'images', 'variants']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'products.manage');

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
            'image_url' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*.image_url' => 'required_with:images|string',
            'images.*.is_primary' => 'nullable|boolean',
            'images.*.alt_text' => 'nullable|string|max:255',
            'images.*.display_order' => 'nullable|integer',
            'specifications' => 'nullable|array',
            'tags' => 'nullable|array',
        ]);

        $product->fill($validated);
        $wasDirty = $product->isDirty();
        $product->save();

        // Handle image updates if images or image_url were passed
        if ($request->has('images') || $request->has('image_url')) {
            $imageItems = [];
            if ($request->has('images') && is_array($request->input('images'))) {
                $imageItems = array_values(array_filter($request->input('images'), function ($img) {
                    return !empty($img['image_url']);
                }));
            } elseif ($request->filled('image_url')) {
                $imageItems = [
                    [
                        'image_url' => $request->input('image_url'),
                        'is_primary' => true,
                        'alt_text' => $product->name,
                        'display_order' => 0,
                    ]
                ];
            }

            // Mandatory check: At least 1 image on modification
            if (empty($imageItems)) {
                return response()->json([
                    'message' => 'Validation error: At least 1 product image is mandatory. Please provide an image URL or upload an image from your device.',
                    'errors' => ['images' => ['At least 1 product image is mandatory.']]
                ], 422);
            }

            // Limit check: Maximum 5 images
            if (count($imageItems) > 5) {
                return response()->json([
                    'message' => 'Validation error: A product cannot have more than 5 images.',
                    'errors' => ['images' => ['Maximum limit is 5 images per product.']]
                ], 422);
            }

            $product->images()->delete();

            $hasPrimary = false;
            foreach ($imageItems as $idx => $img) {
                $isPrimary = !empty($img['is_primary']);
                if ($isPrimary) {
                    if ($hasPrimary) {
                        $isPrimary = false;
                    } else {
                        $hasPrimary = true;
                    }
                }
                $imageItems[$idx]['_calculated_primary'] = $isPrimary;
            }

            if (!$hasPrimary && count($imageItems) > 0) {
                $imageItems[0]['_calculated_primary'] = true;
            }

            foreach ($imageItems as $idx => $img) {
                $product->images()->create([
                    'image_url' => $img['image_url'],
                    'alt_text' => $img['alt_text'] ?? $product->name,
                    'is_primary' => $imageItems[$idx]['_calculated_primary'],
                    'display_order' => $img['display_order'] ?? $idx,
                ]);
            }
            $wasDirty = true;
        }

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
            'product' => $product->load(['category', 'primaryImage', 'images', 'variants']),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'products.manage');

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

    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'products.manage');

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:products,id',
        ]);

        $count = 0;
        $deletedNames = [];

        foreach ($validated['ids'] as $id) {
            $product = Product::find($id);
            if ($product) {
                $name = $product->name;
                $sku = $product->sku;
                $oldValues = $product->toArray();
                $product->delete();
                $deletedNames[] = $name;
                $count++;

                AuditLog::log(
                    $request->user(),
                    'product.deleted',
                    'Product',
                    $id,
                    "Bulk deleted product '{$name}' (SKU: {$sku})",
                    $oldValues,
                    null
                );
            }
        }

        return response()->json([
            'message' => "Successfully deleted {$count} product(s).",
            'deleted_count' => $count,
        ]);
    }
}
