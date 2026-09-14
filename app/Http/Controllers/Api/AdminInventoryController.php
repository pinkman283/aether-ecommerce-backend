<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminInventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'inventory.manage', 'inventory.valuation', 'products.view');

        $query = Product::with(['category', 'primaryImage', 'variants'])->select([
            'id', 'category_id', 'name', 'sku', 'price', 'cost_price', 'stock_quantity', 'is_active', 'updated_at'
        ]);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->input('filter') === 'in_stock') {
            $query->where('stock_quantity', '>', 10);
        } elseif ($request->input('filter') === 'low_stock') {
            $query->where('stock_quantity', '>', 0)->where('stock_quantity', '<=', 10);
        } elseif ($request->input('filter') === 'out_of_stock') {
            $query->where('stock_quantity', '<=', 0);
        } elseif ($request->input('filter') === 'overstocked') {
            $query->where('stock_quantity', '>=', 50);
        }

        $sortBy = $request->input('sort_by', 'urgent_restock');
        if ($sortBy === 'stock_desc') {
            $query->orderBy('stock_quantity', 'desc');
        } elseif ($sortBy === 'name_asc') {
            $query->orderBy('name', 'asc');
        } elseif ($sortBy === 'sku_asc') {
            $query->orderBy('sku', 'asc');
        } elseif ($sortBy === 'price_desc') {
            $query->orderBy('price', 'desc');
        } else {
            $query->orderBy('stock_quantity', 'asc');
        }

        $perPage = (int) $request->input('per_page', 20);
        $page = (int) $request->input('page', 1);
        $inventory = $query->paginate($perPage, ['*'], 'page', $page);

        $summary = [
            'total_skus' => Product::count(),
            'total_units' => (int) Product::sum('stock_quantity'),
            'low_stock_count' => Product::where('stock_quantity', '>', 0)->where('stock_quantity', '<=', 10)->count(),
            'out_of_stock_count' => Product::where('stock_quantity', '<=', 0)->count(),
        ];

        return response()->json([
            'summary' => $summary,
            'inventory' => $inventory,
        ]);
    }

    public function adjustStock(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'inventory.manage', 'inventory.adjust');

        $product = Product::findOrFail($id);
        $oldStock = $product->stock_quantity;

        $validated = $request->validate([
            'adjustment' => 'required|integer', // Can be positive or negative
            'reason' => 'required|string|max:255',
            'variant_id' => 'nullable|integer|exists:product_variants,id',
            'unit_cost' => 'nullable|numeric|min:0',
        ]);

        $variant = !empty($validated['variant_id'])
            ? \App\Models\ProductVariant::where('id', $validated['variant_id'])->where('product_id', $product->id)->first()
            : null;

        $targetStock = $variant ? $variant->stock_quantity : $oldStock;
        $newStock = $targetStock + $validated['adjustment'];

        if ($newStock < 0) {
            return response()->json([
                'message' => "Cannot reduce stock below 0. Current stock is {$targetStock}.",
            ], 422);
        }

        try {
            $movement = \App\Services\InventoryCostingService::adjustStockManually(
                $product,
                $variant,
                (int) $validated['adjustment'],
                $validated['reason'],
                $request->user(),
                isset($validated['unit_cost']) ? (float)$validated['unit_cost'] : null
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AuditLog::log(
            $request->user(),
            'inventory.adjusted',
            'Product',
            $product->id,
            "Adjusted stock for '{$product->name}'" . ($variant ? " (Variant: {$variant->name})" : "") . " by {$validated['adjustment']} units. Reason: {$validated['reason']}",
            ['stock_quantity' => $oldStock],
            ['stock_quantity' => $product->fresh()->stock_quantity, 'reason' => $validated['reason']]
        );

        return response()->json([
            'message' => "Stock for '{$product->name}' updated to {$product->fresh()->stock_quantity} units.",
            'product' => $product->fresh(['category', 'primaryImage', 'variants']),
            'movement' => $movement,
        ]);
    }
}
