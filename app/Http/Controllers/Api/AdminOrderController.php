<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Order::with(['items.product', 'user'])->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_email', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%")
                  ->orWhere('tracking_code', 'like', "%{$search}%")
                  ->orWhere('carrier', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('order_status', $request->input('status'));
        }

        if ($request->filled('payment_status') && $request->input('payment_status') !== 'all') {
            $query->where('payment_status', $request->input('payment_status'));
        }

        if ($request->filled('carrier')) {
            $query->where('carrier', 'like', "%" . $request->input('carrier') . "%");
        }

        if ($request->filled('min_total')) {
            $query->where('total_amount', '>=', (float) $request->input('min_total'));
        }

        if ($request->filled('max_total')) {
            $query->where('total_amount', '<=', (float) $request->input('max_total'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $perPage = (int) $request->input('per_page', 25);
        $orders = $query->paginate($perPage);

        return response()->json($orders);
    }

    public function show(int $id): JsonResponse
    {
        $order = Order::with(['items.product', 'items.variant', 'user'])->findOrFail($id);
        return response()->json($order);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'required|email|max:255',
            'customer_phone' => 'nullable|string|max:30',
            'user_id' => 'nullable|exists:users,id',
            'shipping_address' => 'required|array',
            'shipping_address.full_name' => 'required|string',
            'shipping_address.address_line1' => 'required|string',
            'shipping_address.city' => 'required|string',
            'shipping_address.state' => 'nullable|string',
            'shipping_address.postal_code' => 'required|string',
            'shipping_address.country' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'payment_status' => 'required|in:pending,paid,failed,refunded',
            'payment_method' => 'required|string',
            'order_status' => 'required|in:pending,processing,shipped,delivered,cancelled,refunded',
            'carrier' => 'nullable|string|max:100',
            'tracking_code' => 'nullable|string|max:100',
            'shipping_amount' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $subtotal = 0;
        $orderItemsData = [];

        foreach ($validated['items'] as $item) {
            $product = Product::with('primaryImage')->findOrFail($item['product_id']);
            $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : (float) $product->price;
            $quantity = (int) $item['quantity'];
            $totalPrice = $unitPrice * $quantity;
            $subtotal += $totalPrice;

            // Reduce stock
            $product->decrement('stock_quantity', $quantity);

            $orderItemsData[] = [
                'product_id' => $product->id,
                'variant_id' => $item['variant_id'] ?? null,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'product_image' => $product->primaryImage?->image_url ?? $product->images?->first()?->image_url,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'total_price' => $totalPrice,
            ];
        }

        $shipping = (float) ($validated['shipping_amount'] ?? 0);
        $tax = (float) ($validated['tax_amount'] ?? ($subtotal * 0.08));
        $discount = (float) ($validated['discount_amount'] ?? 0);
        $totalAmount = max(0, $subtotal + $shipping + $tax - $discount);

        $orderNumber = 'ORD-' . strtoupper(Str::random(8));

        $order = Order::create([
            'user_id' => $validated['user_id'] ?? null,
            'order_number' => $orderNumber,
            'customer_name' => $validated['customer_name'],
            'customer_email' => $validated['customer_email'],
            'customer_phone' => $validated['customer_phone'] ?? null,
            'shipping_address' => $validated['shipping_address'],
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'shipping_amount' => $shipping,
            'discount_amount' => $discount,
            'total_amount' => $totalAmount,
            'payment_status' => $validated['payment_status'],
            'payment_method' => $validated['payment_method'],
            'order_status' => $validated['order_status'],
            'carrier' => $validated['carrier'] ?? null,
            'tracking_code' => $validated['tracking_code'] ?? null,
            'notes' => $validated['notes'] ?? 'Order created via Admin Console.',
            'shipped_at' => $validated['order_status'] === 'shipped' ? now() : null,
            'delivered_at' => $validated['order_status'] === 'delivered' ? now() : null,
        ]);

        foreach ($orderItemsData as $itemData) {
            $order->items()->create($itemData);
        }

        AuditLog::log(
            $request->user(),
            'order.created',
            'Order',
            $order->id,
            "Created order #{$order->order_number} for customer {$order->customer_name} (Total: \${$order->total_amount}).",
            null,
            $order->toArray()
        );

        return response()->json([
            'message' => "Order {$order->order_number} created successfully.",
            'order' => $order->load(['items.product', 'user']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $order = Order::with('items')->findOrFail($id);
        $oldValues = $order->toArray();

        $validated = $request->validate([
            'customer_name' => 'sometimes|required|string|max:255',
            'customer_email' => 'sometimes|required|email|max:255',
            'customer_phone' => 'nullable|string|max:30',
            'shipping_address' => 'nullable|array',
            'order_status' => 'sometimes|required|in:pending,processing,shipped,delivered,cancelled,refunded',
            'payment_status' => 'sometimes|required|in:pending,paid,failed,refunded',
            'payment_method' => 'nullable|string',
            'carrier' => 'nullable|string|max:100',
            'tracking_code' => 'nullable|string|max:100',
            'shipping_amount' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        if (isset($validated['order_status']) && $validated['order_status'] === 'shipped' && !$order->shipped_at) {
            $validated['shipped_at'] = now();
        }
        if (isset($validated['order_status']) && $validated['order_status'] === 'delivered' && !$order->delivered_at) {
            $validated['delivered_at'] = now();
        }

        $order->fill($validated);
        $wasDirty = $order->isDirty();
        $order->save();

        if ($wasDirty) {
            AuditLog::log(
                $request->user(),
                'order.updated',
                'Order',
                $order->id,
                "Updated details for order #{$order->order_number}.",
                $oldValues,
                $order->toArray()
            );
        }

        return response()->json([
            'message' => "Order #{$order->order_number} updated successfully.",
            'order' => $order->fresh(['items.product', 'user']),
        ]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $order = Order::with('items')->findOrFail($id);
        $oldStatus = $order->order_status;

        $validated = $request->validate([
            'order_status' => 'required|in:pending,processing,shipped,delivered,cancelled,refunded',
            'carrier' => 'nullable|string|max:100',
            'tracking_code' => 'nullable|string|max:100',
            'payment_status' => 'nullable|in:pending,paid,failed,refunded',
            'notes' => 'nullable|string',
        ]);

        $newStatus = $validated['order_status'];

        // Handle timestamps
        if ($newStatus === 'shipped' && !$order->shipped_at) {
            $order->shipped_at = now();
        }
        if ($newStatus === 'delivered' && !$order->delivered_at) {
            $order->delivered_at = now();
        }

        // If transitioning to cancelled from non-delivered, restore stock
        if ($newStatus === 'cancelled' && $oldStatus !== 'cancelled') {
            foreach ($order->items as $item) {
                if ($item->product_id) {
                    Product::where('id', $item->product_id)->increment('stock_quantity', $item->quantity);
                }
            }
        }

        $order->update($validated);

        AuditLog::log(
            $request->user(),
            'order.status_updated',
            'Order',
            $order->id,
            "Order {$order->order_number} fulfillment status updated from '{$oldStatus}' to '{$newStatus}'.",
            ['status' => $oldStatus],
            ['status' => $newStatus, 'carrier' => $order->carrier, 'tracking_code' => $order->tracking_code]
        );

        return response()->json([
            'message' => "Order {$order->order_number} status updated to '{$newStatus}'.",
            'order' => $order->fresh(['items', 'user']),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $order = Order::with('items')->findOrFail($id);
        $orderNumber = $order->order_number;
        $oldValues = $order->toArray();

        // If order was not delivered/cancelled, restore stock
        if (!in_array($order->order_status, ['cancelled', 'refunded'])) {
            foreach ($order->items as $item) {
                if ($item->product_id) {
                    Product::where('id', $item->product_id)->increment('stock_quantity', $item->quantity);
                }
            }
        }

        $order->items()->delete();
        $order->delete();

        AuditLog::log(
            $request->user(),
            'order.deleted',
            'Order',
            $id,
            "Deleted order #{$orderNumber}.",
            $oldValues,
            null
        );

        return response()->json([
            'message' => "Order #{$orderNumber} deleted successfully.",
        ]);
    }

    public function refund(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        if ($order->payment_status === 'refunded') {
            return response()->json(['message' => 'Order is already marked as refunded.'], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
            'restock' => 'boolean',
        ]);

        $order->update([
            'payment_status' => 'refunded',
            'order_status' => 'refunded',
            'notes' => ($order->notes ? $order->notes . "\n" : "") . "Refunded: " . $validated['reason'],
        ]);

        if (!empty($validated['restock'])) {
            foreach ($order->items as $item) {
                if ($item->product_id) {
                    Product::where('id', $item->product_id)->increment('stock_quantity', $item->quantity);
                }
            }
        }

        AuditLog::log(
            $request->user(),
            'order.refunded',
            'Order',
            $order->id,
            "Order {$order->order_number} was marked as refunded. Reason: {$validated['reason']}"
        );

        return response()->json([
            'message' => "Order {$order->order_number} successfully marked as refunded.",
            'order' => $order->fresh(['items']),
        ]);
    }
}
