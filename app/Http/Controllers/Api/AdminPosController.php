<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosRegisterSession;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\InventoryCostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminPosController extends Controller
{
    public function products(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'pos.access');

        $query = Product::with(['category', 'primaryImage', 'variants'])
            ->where('is_active', true)
            ->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhereHas('variants', function ($vq) use ($search) {
                      $vq->where('sku', 'like', "%{$search}%")
                         ->orWhere('name', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('category_id') && $request->input('category_id') !== 'all') {
            $query->where('category_id', $request->input('category_id'));
        }

        $perPage = (int) $request->input('per_page', 30);
        $products = $query->paginate($perPage);

        return response()->json($products);
    }

    public function checkout(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'pos.create_sale');

        $validated = $request->validate([
            'pos_register_session_id' => 'required|exists:pos_register_sessions,id',
            'customer_id' => 'nullable|exists:users,id',
            'customer_name' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'payment_method' => 'required|string|in:cash,credit_card,debit_card,mobile_money,split',
            'cash_received' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variant_id' => 'nullable|exists:product_variants,id',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
        ]);

        $session = PosRegisterSession::findOrFail($validated['pos_register_session_id']);
        if ($session->status !== 'open') {
            return response()->json(['message' => 'Cannot process sale on a closed register session.'], 422);
        }

        return DB::transaction(function () use ($request, $validated, $session) {
            $subtotal = 0.00;
            $itemsData = [];

            foreach ($validated['items'] as $itemInput) {
                $product = Product::with('primaryImage')->findOrFail($itemInput['product_id']);
                $variant = !empty($itemInput['variant_id']) ? ProductVariant::find($itemInput['variant_id']) : null;
                $unitPrice = (float) $itemInput['unit_price'];
                $quantity = (int) $itemInput['quantity'];
                $itemDiscount = (float) ($itemInput['discount_amount'] ?? 0);
                $itemTotal = max(0, ($unitPrice * $quantity) - $itemDiscount);

                $subtotal += $itemTotal;

                $itemsData[] = [
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'product_name' => $product->name,
                    'product_sku' => $variant?->sku ?: $product->sku,
                    'product_image' => $product->primaryImage?->image_url ?? $product->images?->first()?->image_url,
                    'variant_name' => $variant?->name,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'discount_amount' => $itemDiscount,
                    'total_price' => $itemTotal,
                ];
            }

            $orderDiscount = (float) ($validated['discount_amount'] ?? 0);
            $taxAmount = (float) ($validated['tax_amount'] ?? ($subtotal * 0.08));
            $totalAmount = max(0, $subtotal - $orderDiscount) + $taxAmount;

            $cashReceived = (float) ($validated['cash_received'] ?? $totalAmount);
            $changeReturned = max(0, $cashReceived - $totalAmount);

            $customerName = !empty($validated['customer_name']) ? $validated['customer_name'] : 'Walk-in Customer';
            $customerPhone = !empty($validated['customer_phone']) ? $validated['customer_phone'] : 'N/A';
            $customerEmail = !empty($validated['customer_email']) ? $validated['customer_email'] : ($customerPhone !== 'N/A' ? "pos-{$customerPhone}@pos.local" : 'walkin@pos.local');

            $orderNumber = 'POS-' . date('Ymd') . '-' . strtoupper(Str::random(5));

            // Create Unified Order
            $order = Order::create([
                'user_id' => $validated['customer_id'] ?? null,
                'order_number' => $orderNumber,
                'order_source' => 'pos',
                'pos_register_session_id' => $session->id,
                'cashier_user_id' => $request->user()->id,
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
                'shipping_address' => [
                    'full_name' => $customerName,
                    'address_line1' => 'In-Store POS Terminal Pickup',
                    'city' => 'Store Floor',
                    'postal_code' => '00000',
                    'country' => 'United States',
                ],
                'billing_address' => [
                    'full_name' => $customerName,
                    'address_line1' => 'In-Store POS Terminal',
                    'city' => 'Store Floor',
                    'postal_code' => '00000',
                    'country' => 'United States',
                ],
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'shipping_amount' => 0.00,
                'discount_amount' => $orderDiscount,
                'total_amount' => $totalAmount,
                'cash_received' => $cashReceived,
                'change_returned' => $changeReturned,
                'payment_status' => 'paid',
                'payment_method' => $validated['payment_method'],
                'payment_transaction_id' => 'pos_tx_' . Str::random(12),
                'order_status' => 'delivered', // In-store immediate pickup
                'delivered_at' => now(),
                'notes' => $validated['notes'] ?? 'POS Terminal Checkout Sale',
                'ip_address' => $request->ip(),
            ]);

            foreach ($itemsData as $itemData) {
                $order->items()->create($itemData);
            }

            // 2. Consume FIFO Cost Layers & Compute Real COGS & Gross Profit
            $order = InventoryCostingService::fulfillOrderAndComputeCogs($order);

            // 3. Update Register Session Totals
            if ($validated['payment_method'] === 'cash') {
                $session->increment('cash_sales_amount', $totalAmount);
            } elseif ($validated['payment_method'] === 'credit_card' || $validated['payment_method'] === 'debit_card') {
                $session->increment('card_sales_amount', $totalAmount);
            } else {
                $session->increment('mobile_sales_amount', $totalAmount);
            }

            $session->recalculateExpectedCash();
            $session->save();

            AuditLog::log(
                $request->user(),
                'pos.sale_completed',
                'Order',
                $order->id,
                "Cashier {$request->user()->name} processed POS Sale {$order->order_number} (Total: \${$order->total_amount}, COGS: \${$order->cogs_amount}, Gross Profit: \${$order->gross_profit})."
            );

            return response()->json([
                'message' => "Sale completed successfully! Order #{$order->order_number}",
                'order' => $order->load(['items', 'cashier', 'posSession']),
                'receipt' => [
                    'order_id' => $order->id,
                    'invoice_number' => $order->invoice_number,
                    'order_number' => $order->order_number,
                    'date' => $order->created_at->format('Y-m-d H:i:s'),
                    'cashier' => $request->user()->name,
                    'register' => $session->register?->name ?? 'POS #1',
                    'customer_name' => $customerName,
                    'customer_phone' => $customerPhone,
                    'items' => $order->items,
                    'subtotal' => $subtotal,
                    'discount' => $orderDiscount,
                    'tax' => $taxAmount,
                    'total' => $totalAmount,
                    'payment_method' => $validated['payment_method'],
                    'cash_received' => $cashReceived,
                    'change' => $changeReturned,
                ],
            ], 201);
        });
    }
}
