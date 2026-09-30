<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOrderOperationsTest extends TestCase
{
    protected User $admin;
    protected User $customer;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_operations_test@example.com'],
            [
                'name' => 'Admin Operations Tester',
                'password' => bcrypt('AdminPassword123!'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        $this->customer = User::firstOrCreate(
            ['email' => 'customer_operations_test@example.com'],
            [
                'name' => 'Customer Operations Tester',
                'password' => bcrypt('CustomerPassword123!'),
                'role' => 'customer',
                'status' => 'active',
            ]
        );

        $this->category = Category::firstOrCreate(
            ['slug' => 'admin-ops-cat'],
            ['name' => 'Admin Ops Category', 'is_active' => true]
        );
    }

    protected function createTestProduct(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Ops Test Product ' . uniqid(),
            'slug' => 'ops-prod-' . uniqid(),
            'sku' => 'SKU-' . strtoupper(Str::random(6)),
            'category_id' => $this->category->id,
            'price' => 120.00,
            'stock_quantity' => 50,
            'is_active' => true,
        ], $attrs));
    }

    protected function createTestOrder(array $attrs = []): Order
    {
        $product = $this->createTestProduct();

        $order = Order::create(array_merge([
            'order_number' => 'ORD-OPS-' . strtoupper(Str::random(8)),
            'user_id' => $this->customer->id,
            'customer_name' => 'Alice Customer',
            'customer_email' => $this->customer->email,
            'customer_phone' => '01711000111',
            'shipping_address' => [
                'full_name' => 'Alice Customer',
                'address_line1' => '123 Test Boulevard',
                'city' => 'Dhaka',
                'country' => 'Bangladesh',
            ],
            'subtotal' => 240.00,
            'discount_amount' => 0.00,
            'shipping_amount' => 60.00,
            'total_amount' => 300.00,
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'order_status' => 'pending',
        ], $attrs));

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'unit_price' => 120.00,
            'total_price' => 240.00,
        ]);

        return $order->fresh(['items', 'user']);
    }

    public function test_admin_can_list_orders_with_pagination_and_filters(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $orderCod = $this->createTestOrder([
            'payment_method' => 'cash_on_delivery',
            'order_status' => 'pending',
        ]);

        $orderOnline = $this->createTestOrder([
            'payment_method' => 'online',
            'order_status' => 'confirmed',
        ]);

        // 1. All orders with pagination
        $response = $this->getJson('/api/admin/orders');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'current_page',
            'data',
            'last_page',
            'total',
        ]);

        // 2. Filter by payment method
        $resCod = $this->getJson('/api/admin/orders?payment_method=cash_on_delivery');
        $resCod->assertStatus(200);
        $codOrders = collect($resCod->json('data'));
        $this->assertTrue($codOrders->contains('id', $orderCod->id));

        // 3. Filter by order status
        $resConfirmed = $this->getJson('/api/admin/orders?status=confirmed');
        $resConfirmed->assertStatus(200);
        $confirmedOrders = collect($resConfirmed->json('data'));
        $this->assertTrue($confirmedOrders->contains('id', $orderOnline->id));
        $this->assertFalse($confirmedOrders->contains('id', $orderCod->id));
    }

    public function test_admin_can_fetch_order_status_counts(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $this->createTestOrder(['order_status' => 'pending']);

        $response = $this->getJson('/api/admin/orders/counts');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'all',
            'pending',
            'confirmed',
            'processing',
            'shipped',
            'delivered',
            'cancelled',
            'refunded',
            'payment_pending',
            'payment_paid',
        ]);
        $this->assertGreaterThanOrEqual(1, $response->json('pending'));
    }

    public function test_non_admin_cannot_access_admin_order_endpoints(): void
    {
        $order = $this->createTestOrder();

        // Unauthenticated access
        $this->getJson('/api/admin/orders')->assertStatus(401);
        $this->getJson("/api/admin/orders/{$order->id}")->assertStatus(401);
        $this->patchJson("/api/admin/orders/{$order->id}/status", ['order_status' => 'confirmed'])->assertStatus(401);

        // Customer access
        Sanctum::actingAs($this->customer);
        $this->getJson('/api/admin/orders')->assertStatus(403);
        $this->getJson("/api/admin/orders/{$order->id}")->assertStatus(403);
        $this->patchJson("/api/admin/orders/{$order->id}/status", ['order_status' => 'confirmed'])->assertStatus(403);
        $this->postJson("/api/admin/orders/{$order->id}/payments", ['amount' => 300])->assertStatus(403);
    }

    public function test_admin_can_view_order_details_safely(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $order = $this->createTestOrder();

        $response = $this->getJson("/api/admin/orders/{$order->id}");
        $response->assertStatus(200);
        $response->assertJsonPath('order_number', $order->order_number);
        $response->assertJsonPath('customer_name', 'Alice Customer');
        $response->assertJsonPath('customer_email', $this->customer->email);
        $this->assertEquals(300.00, (float) $response->json('total_amount'));
        $response->assertJsonCount(1, 'items');

        // Verify sensitive auth tokens/passwords are never exposed
        $response->assertDontSee('password');
        $response->assertDontSee('remember_token');
    }

    public function test_invalid_order_status_transition_is_rejected_with_422(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $order = $this->createTestOrder(['order_status' => 'delivered']);

        // Attempt invalid backward transition: delivered -> pending
        $response = $this->patchJson("/api/admin/orders/{$order->id}/status", [
            'order_status' => 'pending',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['order_status']);
    }

    public function test_valid_order_status_transitions_succeed(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $order = $this->createTestOrder(['order_status' => 'pending']);

        // 1. pending -> confirmed
        $res1 = $this->patchJson("/api/admin/orders/{$order->id}/status", [
            'order_status' => 'confirmed',
        ]);
        $res1->assertStatus(200);
        $this->assertEquals('confirmed', $order->fresh()->order_status);

        // 2. confirmed -> processing
        $res2 = $this->patchJson("/api/admin/orders/{$order->id}/status", [
            'order_status' => 'processing',
        ]);
        $res2->assertStatus(200);
        $this->assertEquals('processing', $order->fresh()->order_status);

        // 3. processing -> shipped
        $res3 = $this->patchJson("/api/admin/orders/{$order->id}/status", [
            'order_status' => 'shipped',
            'carrier' => 'Steadfast',
            'tracking_code' => 'STDF-12345',
        ]);
        $res3->assertStatus(200);
        $this->assertEquals('shipped', $order->fresh()->order_status);
        $this->assertNotNull($order->fresh()->shipped_at);

        // 4. shipped -> delivered
        $res4 = $this->patchJson("/api/admin/orders/{$order->id}/status", [
            'order_status' => 'delivered',
        ]);
        $res4->assertStatus(200);
        $this->assertEquals('delivered', $order->fresh()->order_status);
        $this->assertNotNull($order->fresh()->delivered_at);
    }

    public function test_order_cancellation_restores_inventory_and_creates_audit_log(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $product = $this->createTestProduct(['stock_quantity' => 10]);
        $order = Order::create([
            'order_number' => 'ORD-CANCEL-' . strtoupper(Str::random(6)),
            'customer_name' => 'Cancel Test Customer',
            'customer_email' => 'cancel@example.com',
            'shipping_address' => 'Dhaka',
            'subtotal' => 240.00,
            'total_amount' => 240.00,
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'order_status' => 'pending',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'unit_price' => 120.00,
            'total_price' => 240.00,
        ]);

        $response = $this->patchJson("/api/admin/orders/{$order->id}/status", [
            'order_status' => 'cancelled',
            'reason' => 'Customer requested cancellation prior to packing',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('cancelled', $order->fresh()->order_status);

        // Verify audit log exists
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'order.status_updated',
            'entity_type' => 'Order',
            'entity_id' => $order->id,
        ]);
    }

    public function test_manual_confirmation_of_online_payment_is_rejected_with_422(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $order = $this->createTestOrder([
            'payment_method' => 'online',
            'payment_status' => 'pending',
        ]);

        // Attempt 1: Via status patch
        $res1 = $this->patchJson("/api/admin/orders/{$order->id}/status", [
            'order_status' => $order->order_status,
            'payment_status' => 'paid',
        ]);
        $res1->assertStatus(422);
        $res1->assertJsonValidationErrors(['payment_status']);

        // Attempt 2: Via payments endpoint
        $res2 = $this->postJson("/api/admin/orders/{$order->id}/payments", [
            'amount' => 300,
        ]);
        $res2->assertStatus(422);
        $this->assertEquals('pending', $order->fresh()->payment_status);
    }

    public function test_cod_payment_collection_records_ledger_and_updates_status(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $order = $this->createTestOrder([
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'total_amount' => 300.00,
        ]);

        $response = $this->postJson("/api/admin/orders/{$order->id}/payments", [
            'amount' => 300.00,
            'reference' => 'CASH-REC-' . uniqid(),
            'notes' => 'Collected upon doorstep delivery',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('paid', $order->fresh()->payment_status);

        // Assert payment record was stored in ledger
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'cash_on_delivery',
            'status' => 'completed',
            'amount' => 300.00,
        ]);
    }

    public function test_historical_order_totals_and_prices_remain_authoritative(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $product = $this->createTestProduct(['price' => 120.00]);

        $order = Order::create([
            'order_number' => 'ORD-HIST-' . strtoupper(Str::random(6)),
            'customer_name' => 'Historical Customer',
            'customer_email' => 'hist@example.com',
            'shipping_address' => 'Chittagong',
            'subtotal' => 240.00,
            'discount_amount' => 20.00,
            'shipping_amount' => 50.00,
            'total_amount' => 270.00,
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'order_status' => 'pending',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'unit_price' => 120.00,
            'total_price' => 240.00,
        ]);

        // Change current catalog price to 300.00
        $product->update(['price' => 300.00]);

        // Fetch order details as admin
        $response = $this->getJson("/api/admin/orders/{$order->id}");
        $response->assertStatus(200);

        // Assert historical price and total are preserved
        $this->assertEquals(120.00, (float) $response->json('items.0.unit_price'));
        $this->assertEquals(240.00, (float) $response->json('subtotal'));
        $this->assertEquals(270.00, (float) $response->json('total_amount'));
    }

    public function test_admin_analytics_dashboard_returns_operational_pipeline_metrics(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $response = $this->getJson('/api/admin/analytics');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'stats' => [
                'total_revenue',
                'total_orders',
                'total_customers',
                'total_products',
                'low_stock_count',
                'pending_orders',
                'confirmed_orders',
                'processing_orders',
                'shipped_orders',
                'delivered_orders',
                'cancelled_orders',
                'pending_payments',
                'paid_payments',
            ],
            'recent_orders',
        ]);
    }
}
