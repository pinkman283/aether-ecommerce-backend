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
        $query = Product::with(['category', 'primaryImage', 'variants'])->select([
            'id', 'category_id', 'name', 'sku', 'price', 'stock_quantity', 'is_active', 'updated_at'
        ]);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if ($request->input('filter') === 'low_stock') {
            $query->where('stock_quantity', '>', 0)->where('stock_quantity', '<=', 10);
        } elseif ($request->input('filter') === 'out_of_stock') {
            $query->where('stock_quantity', '<=', 0);
        }

        $perPage = (int) $request->input('per_page', 20);
        $inventory = $query->orderBy('stock_quantity', 'asc')->paginate($perPage);

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
        $product = Product::findOrFail($id);
        $oldStock = $product->stock_quantity;

        $validated = $request->validate([
            'adjustment' => 'required|integer', // Can be positive or negative
            'reason' => 'required|string|max:255',
        ]);

        $newStock = $oldStock + $validated['adjustment'];

        if ($newStock < 0) {
            return response()->json([
                'message' => "Cannot reduce stock below 0. Current stock is {$oldStock}.",
            ], 422);
        }

        $product->update(['stock_quantity' => $newStock]);

        AuditLog::log(
            $request->user(),
            'inventory.adjusted',
            'Product',
            $product->id,
            "Adjusted stock for '{$product->name}' (SKU: {$product->sku}) by {$validated['adjustment']} units (From {$oldStock} to {$newStock}). Reason: {$validated['reason']}",
            ['stock_quantity' => $oldStock],
            ['stock_quantity' => $newStock, 'reason' => $validated['reason']]
        );

        return response()->json([
            'message' => "Stock for '{$product->name}' updated to {$newStock} units.",
            'product' => $product,
        ]);
    }
}
