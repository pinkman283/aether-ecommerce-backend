<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Strictly scoped to authenticated customer orders
        $orders = Order::where('user_id', $user->id)
            ->with('items')
            ->latest()
            ->paginate(10);

        return response()->json($orders);
    }

    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $order = Order::where(function ($q) use ($orderNumber) {
            $q->where('order_number', $orderNumber)
              ->orWhere('id', $orderNumber);
        })->with(['items', 'user'])->firstOrFail();

        // Strict Resource Ownership Check (IDOR Protection)
        if ($order->user_id !== $user->id && !$user->isStaffOrAdmin()) {
            return response()->json([
                'message' => 'Access Denied: You do not have authorization to access this order record.',
            ], 403);
        }

        return response()->json($order);
    }

    public function track(string $orderNumber): JsonResponse
    {
        $order = Order::where('order_number', $orderNumber)
            ->orWhere('tracking_code', $orderNumber)
            ->with('items')
            ->firstOrFail();

        // Mask customer name for privacy protection on public tracking
        $nameParts = explode(' ', trim($order->customer_name));
        $maskedName = implode(' ', array_map(function ($part) {
            return strlen($part) > 1 ? substr($part, 0, 1) . str_repeat('*', max(1, strlen($part) - 1)) : $part;
        }, $nameParts));

        $shippingAddress = is_array($order->shipping_address) ? $order->shipping_address : [];
        $sanitizedAddress = [
            'city' => $shippingAddress['city'] ?? 'City',
            'state' => $shippingAddress['state'] ?? null,
            'country' => $shippingAddress['country'] ?? 'Country',
        ];

        return response()->json([
            'order_number' => $order->order_number,
            'customer_name' => $maskedName,
            'order_status' => $order->order_status,
            'payment_status' => $order->payment_status,
            'carrier' => $order->carrier ?? 'Standard Express',
            'tracking_code' => $order->tracking_code ?? 'TRK-' . strtoupper(Str::random(10)),
            'total_amount' => $order->total_amount,
            'created_at' => $order->created_at,
            'shipped_at' => $order->shipped_at,
            'delivered_at' => $order->delivered_at,
            'shipping_destination' => $sanitizedAddress,
            'items' => $order->items,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        // 1. IP Block Enforcement (Generic 403)
        $clientIp = $request->ip();
        if (\App\Services\CustomerRiskService::isIpBlocked($clientIp)) {
            return response()->json([
                'message' => 'Your request cannot be completed at this time.',
            ], 403);
        }

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

        // 2. Customer Account Status & Guest Unification
        $user = $request->user('sanctum');

        if ($user) {
            if ($user->isBlocked() || $user->isSuspended()) {
                return response()->json([
                    'message' => 'Account access restricted. Please contact customer support.',
                ], 403);
            }
            $customerRecord = $user;
        } else {
            $email = strtolower(trim($validated['customer_email']));
            $existingCustomer = User::where('role', 'customer')->where('email', $email)->first();

            if ($existingCustomer) {
                if ($existingCustomer->isBlocked() || $existingCustomer->isSuspended()) {
                    return response()->json([
                        'message' => 'Account access restricted. Please contact customer support.',
                    ], 403);
                }
                $customerRecord = $existingCustomer;
            } else {
                $customerRecord = User::create([
                    'name' => $validated['customer_name'],
                    'email' => $email,
                    'phone' => $validated['customer_phone'] ?? null,
                    'role' => 'customer',
                    'customer_type' => 'guest',
                    'status' => 'active',
                    'password' => \Illuminate\Support\Facades\Hash::make(Str::random(32)),
                ]);
            }
        }

        $result = DB::transaction(function () use ($request, $validated, $customerRecord, $clientIp) {
            $subtotal = 0.00;
            $itemsToCreate = [];

            foreach ($validated['items'] as $itemData) {
                // Concurrency-safe row locking
                $product = Product::where('id', $itemData['product_id'])->lockForUpdate()->firstOrFail();
                $unitPrice = (float) $product->price;
                $variantName = null;
                $productImage = $product->primaryImage?->image_url ?? $product->images->first()?->image_url;

                // Enforce stock availability
                if ($product->stock_quantity < $itemData['quantity']) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'items' => ["Insufficient inventory for product '{$product->name}'. Only {$product->stock_quantity} unit(s) available."],
                    ]);
                }

                $variant = null;
                if (!empty($itemData['variant_id'])) {
                    $variant = ProductVariant::where('id', $itemData['variant_id'])->lockForUpdate()->firstOrFail();
                    
                    if ($variant->stock_quantity < $itemData['quantity']) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'items' => ["Insufficient inventory for variant '{$variant->name}'. Only {$variant->stock_quantity} unit(s) available."],
                        ]);
                    }

                    $unitPrice += (float) $variant->price_modifier;
                    $variantName = $variant->name;
                }

                $totalItemPrice = $unitPrice * $itemData['quantity'];
                $subtotal += $totalItemPrice;

                // Decrement inventory securely inside lock
                $product->decrement('stock_quantity', $itemData['quantity']);
                if (!empty($itemData['variant_id'])) {
                    $variant->decrement('stock_quantity', $itemData['quantity']);
                }

                $itemsToCreate[] = [
                    'product_id' => $product->id,
                    'variant_id' => $itemData['variant_id'] ?? null,
                    'product_name' => $product->name,
                    'product_sku' => $variant ? $variant->sku : $product->sku,
                    'product_image' => $productImage,
                    'variant_name' => $variantName,
                    'unit_price' => $unitPrice,
                    'quantity' => $itemData['quantity'],
                    'total_price' => $totalItemPrice,
                ];
            }

            // Server-side authoritative coupon calculation
            $discount = 0.00;
            if (!empty($validated['coupon_code'])) {
                $coupon = Coupon::where('code', strtoupper($validated['coupon_code']))
                    ->where('is_active', true)
                    ->where('starts_at', '<=', now())
                    ->where('expires_at', '>=', now())
                    ->first();

                if ($coupon && $subtotal >= (float) $coupon->min_order_amount) {
                    if ($coupon->discount_type === 'percentage') {
                        $discount = round(($subtotal * (float) $coupon->discount_value) / 100, 2);
                        if ($coupon->max_discount_amount && $discount > (float) $coupon->max_discount_amount) {
                            $discount = (float) $coupon->max_discount_amount;
                        }
                    } else {
                        $discount = min($subtotal, (float) $coupon->discount_value);
                    }
                }
            }

            // Authoritative server-side shipping, tax, and total calculation
            $shipping = $subtotal >= 100 ? 0.00 : 15.00;
            $taxable = max(0, $subtotal - $discount);
            $tax = round($taxable * 0.08, 2);
            $total = round($taxable + $shipping + $tax, 2);

            $orderNumber = 'ORD-' . date('Y') . '-' . strtoupper(Str::random(6));

            $order = Order::create([
                'user_id' => $customerRecord->id,
                'order_number' => $orderNumber,
                'order_source' => 'online',
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
                'ip_address' => $clientIp,
            ]);

            foreach ($itemsToCreate as $item) {
                $order->items()->create($item);
            }

            // Log IP record
            \App\Models\CustomerIpLog::record($customerRecord, $clientIp, 'order_created', $order->id);

            // Execute FIFO Costing Layer Consumption & Compute COGS
            $order = \App\Services\InventoryCostingService::fulfillOrderAndComputeCogs($order);

            // Update risk score
            \App\Services\CustomerRiskService::calculateCustomerRisk($customerRecord);

            return $order->load('items');
        });

        return response()->json([
            'message' => 'Order created successfully',
            'order' => $result,
        ], 201);
    }
}
