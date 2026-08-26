<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function analytics(): JsonResponse
    {
        $totalRevenue = Order::where('payment_status', 'paid')->sum('total_amount');
        $totalOrders = Order::count();
        $totalCustomers = User::where('role', 'customer')->count();
        $totalProducts = Product::count();
        $lowStockProducts = Product::where('stock_quantity', '<=', 10)->count();

        // Recent orders
        $recentOrders = Order::latest()->take(8)->with('items')->get();

        // Top selling products
        $topProducts = Product::with('primaryImage')
            ->orderBy('review_count', 'desc')
            ->take(5)
            ->get();

        // Monthly revenue breakdown (sample 6 months)
        $salesTrend = [
            ['month' => 'Mar', 'sales' => 14200, 'orders' => 48],
            ['month' => 'Apr', 'sales' => 18900, 'orders' => 64],
            ['month' => 'May', 'sales' => 24500, 'orders' => 82],
            ['month' => 'Jun', 'sales' => 31200, 'orders' => 105],
            ['month' => 'Jul', 'sales' => 28400, 'orders' => 96],
            ['month' => 'Aug', 'sales' => 39800, 'orders' => 134],
        ];

        return response()->json([
            'stats' => [
                'total_revenue' => round($totalRevenue, 2),
                'total_orders' => $totalOrders,
                'total_customers' => $totalCustomers,
                'total_products' => $totalProducts,
                'low_stock_count' => $lowStockProducts,
            ],
            'recent_orders' => $recentOrders,
            'top_products' => $topProducts,
            'sales_trend' => $salesTrend,
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $query = Order::with(['items', 'user'])->latest();

        if ($request->filled('status')) {
            $query->where('order_status', $request->input('status'));
        }

        $orders = $query->paginate(15);
        return response()->json($orders);
    }

    public function updateOrderStatus(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        $validated = $request->validate([
            'order_status' => 'required|in:pending,processing,shipped,delivered,cancelled',
            'payment_status' => 'nullable|in:pending,paid,failed,refunded',
            'carrier' => 'nullable|string',
            'tracking_code' => 'nullable|string',
        ]);

        if ($validated['order_status'] === 'shipped' && !$order->shipped_at) {
            $order->shipped_at = now();
        }
        if ($validated['order_status'] === 'delivered' && !$order->delivered_at) {
            $order->delivered_at = now();
        }

        $order->update($validated);

        return response()->json([
            'message' => 'Order status updated successfully',
            'order' => $order,
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $query = Product::with(['category', 'primaryImage', 'variants'])->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%");
        }

        $products = $query->paginate(20);
        return response()->json($products);
    }

    public function storeProduct(Request $request): JsonResponse
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
            'image_url' => 'nullable|string|url',
        ]);

        $slug = Str::slug($validated['name']) . '-' . Str::random(4);
        $sku = 'PRD-' . strtoupper(Str::random(6));

        $product = Product::create([
            'category_id' => $validated['category_id'],
            'name' => $validated['name'],
            'slug' => $slug,
            'brand' => $validated['brand'] ?? 'Premium Tech',
            'sku' => $sku,
            'price' => $validated['price'],
            'compare_at_price' => $validated['compare_at_price'] ?? null,
            'stock_quantity' => $validated['stock_quantity'],
            'short_description' => $validated['short_description'] ?? null,
            'description' => $validated['description'],
            'is_featured' => $validated['is_featured'] ?? false,
            'is_new_arrival' => $validated['is_new_arrival'] ?? true,
            'is_best_seller' => $validated['is_best_seller'] ?? false,
            'is_active' => true,
            'rating_average' => 5.0,
            'review_count' => 0,
        ]);

        if (!empty($validated['image_url'])) {
            $product->images()->create([
                'image_url' => $validated['image_url'],
                'alt_text' => $product->name,
                'is_primary' => true,
                'display_order' => 0,
            ]);
        }

        return response()->json([
            'message' => 'Product created successfully',
            'product' => $product->load(['category', 'primaryImage']),
        ], 201);
    }

    public function updateProduct(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category_id' => 'sometimes|required|exists:categories,id',
            'price' => 'sometimes|required|numeric|min:0',
            'compare_at_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'sometimes|required|integer|min:0',
            'short_description' => 'nullable|string',
            'description' => 'sometimes|required|string',
            'is_featured' => 'boolean',
            'is_new_arrival' => 'boolean',
            'is_best_seller' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $product->update($validated);

        return response()->json([
            'message' => 'Product updated successfully',
            'product' => $product->load(['category', 'primaryImage', 'variants']),
        ]);
    }

    public function deleteProduct(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $product->delete();

        return response()->json(['message' => 'Product deleted successfully']);
    }
}
