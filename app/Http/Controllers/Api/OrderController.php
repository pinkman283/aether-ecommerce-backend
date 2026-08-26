<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $query = Order::with('items')->latest();

        if ($user->role !== 'admin') {
            $query->where('user_id', $user->id);
        }

        $orders = $query->paginate(10);

        return response()->json($orders);
    }

    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $user = $request->user('sanctum');

        $query = Order::where('order_number', $orderNumber)->orWhere('id', $orderNumber)->with(['items', 'user']);

        $order = $query->firstOrFail();

        // Check permission if authenticated and not admin
        if ($user && $user->role !== 'admin' && $order->user_id && $order->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($order);
    }

    public function track(string $orderNumber): JsonResponse
    {
        $order = Order::where('order_number', $orderNumber)
            ->orWhere('tracking_code', $orderNumber)
            ->with('items')
            ->firstOrFail();

        return response()->json([
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name,
            'order_status' => $order->order_status,
            'payment_status' => $order->payment_status,
            'carrier' => $order->carrier ?? 'Standard Express',
            'tracking_code' => $order->tracking_code ?? 'TRK-' . strtoupper(Str::random(10)),
            'total_amount' => $order->total_amount,
            'created_at' => $order->created_at,
            'shipped_at' => $order->shipped_at,
            'delivered_at' => $order->delivered_at,
            'shipping_address' => $order->shipping_address,
            'items' => $order->items,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'required|email|max:255',
            'customer_phone' => 'nullable|string|max:30',
            'shipping_address' => 'required|array',
            'shipping_address.address_line1' => 'required|string',
            'shipping_address.city' => 'required|string',
            'shipping_address.postal_code' => 'required|string',
            'shipping_address.country' => 'required|string',
            'billing_address' => 'nullable|array',
            'payment_method' => 'required|in:credit_card,cash_on_delivery,paypal,apple_pay',
            'coupon_code' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $user = $request->user('sanctum');

        $result = DB::transaction(function () use ($validated, $user) {
            $subtotal = 0.00;
            $itemsToCreate = [];

            foreach ($validated['items'] as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);
                $unitPrice = (float) $product->price;
                $variantName = null;
                $productImage = $product->primaryImage?->image_url ?? $product->images->first()?->image_url;

                if (!empty($itemData['variant_id'])) {
                    $variant = ProductVariant::findOrFail($itemData['variant_id']);
                    $unitPrice += (float) $variant->price_modifier;
                    $variantName = $variant->name;
                    
                    // Decrement variant stock
                    if ($variant->stock_quantity > 0) {
                        $variant->decrement('stock_quantity', $itemData['quantity']);
                    }
                }

                // Decrement product stock
                if ($product->stock_quantity > 0) {
                    $product->decrement('stock_quantity', $itemData['quantity']);
                }

                $itemTotal = $unitPrice * $itemData['quantity'];
                $subtotal += $itemTotal;

                $itemsToCreate[] = [
                    'product_id' => $product->id,
                    'variant_id' => $itemData['variant_id'] ?? null,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'product_image' => $productImage,
                    'variant_name' => $variantName,
                    'unit_price' => $unitPrice,
                    'quantity' => $itemData['quantity'],
                    'total_price' => $itemTotal,
                ];
            }

            // Coupon calculation
            $discount = 0.00;
            if (!empty($validated['coupon_code'])) {
                $coupon = Coupon::where('code', strtoupper(trim($validated['coupon_code'])))->first();
                if ($coupon && $coupon->isValid($subtotal)) {
                    $discount = $coupon->calculateDiscount($subtotal);
                    $coupon->increment('used_count');
                }
            }

            $shipping = $subtotal >= 100 ? 0.00 : 15.00;
            $taxable = max(0, $subtotal - $discount);
            $tax = round($taxable * 0.08, 2);
            $total = $taxable + $shipping + $tax;

            $orderNumber = 'ORD-' . date('Y') . '-' . strtoupper(Str::random(6));

            $order = Order::create([
                'user_id' => $user?->id,
                'order_number' => $orderNumber,
                'customer_name' => $validated['customer_name'],
                'customer_email' => $validated['customer_email'],
                'customer_phone' => $validated['customer_phone'] ?? null,
                'shipping_address' => $validated['shipping_address'],
                'billing_address' => $validated['billing_address'] ?? $validated['shipping_address'],
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'shipping_amount' => $shipping,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'payment_status' => $validated['payment_method'] === 'cash_on_delivery' ? 'pending' : 'paid',
                'payment_method' => $validated['payment_method'],
                'payment_transaction_id' => 'tx_' . Str::random(16),
                'order_status' => 'processing',
                'tracking_code' => 'TRK-' . date('md') . strtoupper(Str::random(6)),
                'carrier' => 'DHL Express Cyber Priority',
                'coupon_code' => $validated['coupon_code'] ?? null,
            ]);

            foreach ($itemsToCreate as $item) {
                $order->items()->create($item);
            }

            return $order->load('items');
        });

        return response()->json([
            'message' => 'Order created successfully',
            'order' => $result,
        ], 201);
    }
}
