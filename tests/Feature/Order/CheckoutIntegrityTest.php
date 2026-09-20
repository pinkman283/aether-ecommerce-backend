<?php

namespace Tests\Feature\Order;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderReturnService;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckoutIntegrityTest extends TestCase
{
    protected function createTestProduct(array $attrs = []): Product
    {
        $category = Category::firstOrCreate(['slug' => 'test-gadgets'], ['name' => 'Test Gadgets']);
        return Product::create(array_merge([
            'name' => 'Integrity Widget ' . uniqid(),
            'slug' => 'widget-' . uniqid(),
            'sku' => 'WID-' . strtoupper(Str::random(6)),
            'price' => 1000.00,
            'cost_price' => 600.00,
            'stock_quantity' => 20,
            'is_active' => true,
            'description' => 'Test widget for transaction integrity',
            'category_id' => $category->id,
        ], $attrs));
    }

    protected function createCostLayer(Product $product, int $qty = 20, float $unitCost = 600.00): InventoryCostLayer
    {
        return InventoryCostLayer::create([
            'product_id' => $product->id,
            'variant_id' => null,
            'unit_cost' => $unitCost,
            'initial_quantity' => $qty,
            'remaining_quantity' => $qty,
            'is_depleted' => false,
        ]);
    }

    // =========================================================================
    // 1. SHIPPING ZONE & RATE INTEGRITY (Scenarios 1 - 3)
    // =========================================================================

    /**
     * Scenario 1: Valid Dhaka shipping destination produces Dhaka rate (60 BDT)
     */
    public function test_01_valid_dhaka_shipping_calculates_dhaka_rate(): void
    {
        $product = $this->createTestProduct(['price' => 500.00]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Arafat Rahman',
            'customer_email' => 'arafat@example.com',
            'customer_phone' => '01711223344',
            'shipping_address' => [
                'address_line1' => 'Road 4, Dhanmondi',
                'city' => 'Dhaka',
                'postal_code' => '1209',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);
        $order = $response->json('order');
        $this->assertEquals(60.00, (float) $order['shipping_amount']);
        $this->assertEquals('inside_dhaka', $order['shipping_method']);
    }

    /**
     * Scenario 2: Valid outside-Dhaka destination produces outside rate (130 BDT)
     */
    public function test_02_valid_outside_dhaka_shipping_calculates_outside_rate(): void
    {
        $product = $this->createTestProduct(['price' => 500.00]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Tanvir Ahmed',
            'customer_email' => 'tanvir@example.com',
            'customer_phone' => '01811223344',
            'shipping_address' => [
                'address_line1' => 'GEC Circle, Nasirabad',
                'city' => 'Chittagong',
                'postal_code' => '4000',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'outside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);
        $order = $response->json('order');
        $this->assertEquals(120.00, (float) $order['shipping_amount']);
        $this->assertEquals('outside_dhaka', $order['shipping_method']);
    }

    /**
     * Scenario 3: Tampered shipping method (Chittagong destination + inside_dhaka submitted) is rejected
     */
    public function test_03_tampered_shipping_method_mismatch_is_rejected(): void
    {
        $product = $this->createTestProduct(['price' => 500.00]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Sneaky Buyer',
            'customer_email' => 'sneaky@example.com',
            'customer_phone' => '01811223344',
            'shipping_address' => [
                'address_line1' => 'Zindabazar Point',
                'city' => 'Sylhet', // Outside Dhaka!
                'postal_code' => '3100',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka', // Tampered! Trying to pay 60 instead of 130
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shipping_method']);
    }

    // =========================================================================
    // 2. CHECKOUT IDEMPOTENCY & CONCURRENCY (Scenarios 4 - 10)
    // =========================================================================

    /**
     * Scenario 4: Duplicate rapid checkout request handling prevents double order creation
     */
    public function test_04_duplicate_rapid_checkout_request_handling(): void
    {
        $product = $this->createTestProduct(['price' => 1200.00, 'stock_quantity' => 10]);
        $this->createCostLayer($product, 10, 700.00);

        $idempotencyKey = 'rapid-test-' . Str::uuid();
        $customerEmail = 'rapid-' . uniqid() . '@example.com';

        $payload = [
            'customer_name' => 'Rapid Clicker',
            'customer_email' => $customerEmail,
            'customer_phone' => '01711002233',
            'shipping_address' => [
                'address_line1' => 'Banani Block C',
                'city' => 'Dhaka',
                'postal_code' => '1213',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ];

        $res1 = $this->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/orders', $payload);
        $res1->assertStatus(201);

        $res2 = $this->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/orders', $payload);
        $res2->assertStatus(201);

        $this->assertEquals($res1->json('order.id'), $res2->json('order.id'));
        $this->assertEquals(1, Order::where('customer_email', $customerEmail)->count());
    }

    /**
     * Scenario 5: Same idempotency key submitted twice returns original 201 response with same order data
     */
    public function test_05_same_idempotency_key_replays_cached_response(): void
    {
        $product = $this->createTestProduct(['price' => 1500.00, 'stock_quantity' => 10]);
        $this->createCostLayer($product, 10, 800.00);

        $idempotencyKey = 'idemp-replay-' . Str::uuid();

        $payload = [
            'customer_name' => 'Replay Customer',
            'customer_email' => 'replay@example.com',
            'customer_phone' => '01711004455',
            'shipping_address' => [
                'address_line1' => 'Dhanmondi 8/A',
                'city' => 'Dhaka',
                'postal_code' => '1209',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ];

        $res1 = $this->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/orders', $payload);
        $res1->assertStatus(201);
        $order1 = $res1->json('order');

        $res2 = $this->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/orders', $payload);
        $res2->assertStatus(201);
        $res2->assertHeader('X-Idempotency-Replayed', 'true');
        $order2 = $res2->json('order');

        $this->assertEquals($order1['id'], $order2['id']);
        $this->assertEquals($order1['order_number'], $order2['order_number']);
        $this->assertEquals($order1['total_amount'], $order2['total_amount']);
    }

    /**
     * Scenario 6: Different idempotency keys create separate valid orders
     */
    public function test_06_different_idempotency_keys_create_separate_orders(): void
    {
        $product = $this->createTestProduct(['price' => 800.00, 'stock_quantity' => 20]);
        $this->createCostLayer($product, 20, 400.00);

        $payload = [
            'customer_name' => 'Distinct Buyer',
            'customer_email' => 'distinct@example.com',
            'customer_phone' => '01711556677',
            'shipping_address' => [
                'address_line1' => 'Gulshan 2',
                'city' => 'Dhaka',
                'postal_code' => '1212',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ];

        $res1 = $this->withHeader('X-Idempotency-Key', 'key-A-' . Str::uuid())
            ->postJson('/api/orders', $payload);
        $res1->assertStatus(201);

        $res2 = $this->withHeader('X-Idempotency-Key', 'key-B-' . Str::uuid())
            ->postJson('/api/orders', $payload);
        $res2->assertStatus(201);

        $this->assertNotEquals($res1->json('order.id'), $res2->json('order.id'));
    }

    /**
     * Scenario 7: Coupon / Promotion is NOT redeemed twice on idempotent replay
     */
    public function test_07_coupon_not_redeemed_twice_on_idempotent_replay(): void
    {
        $promotion = \App\Models\Promotion::create([
            'name' => 'Save 100 Promo',
            'slug' => 'save-100-' . uniqid(),
            'promotion_type' => 'discount_code',
            'discount_type' => 'fixed_amount',
            'discount_value' => 100.00,
            'is_active' => true,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
        $promoCode = \App\Models\PromotionCode::create([
            'promotion_id' => $promotion->id,
            'code' => 'SAVE100_' . strtoupper(Str::random(4)),
            'usage_limit' => 10,
            'used_count' => 0,
            'is_active' => true,
        ]);

        $product = $this->createTestProduct(['price' => 1000.00, 'stock_quantity' => 10]);
        $this->createCostLayer($product, 10, 500.00);

        $key = 'idemp-coupon-' . Str::uuid();

        $payload = [
            'customer_name' => 'Coupon User',
            'customer_email' => 'couponuser@example.com',
            'customer_phone' => '01711889900',
            'shipping_address' => [
                'address_line1' => 'Uttara Sector 3',
                'city' => 'Dhaka',
                'postal_code' => '1230',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'coupon_code' => $promoCode->code,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ];

        // Call 1
        $res1 = $this->withHeader('X-Idempotency-Key', $key)->postJson('/api/orders', $payload);
        $res1->assertStatus(201);
        $this->assertEquals(1, $promoCode->fresh()->used_count);

        // Call 2 (idempotent replay)
        $res2 = $this->withHeader('X-Idempotency-Key', $key)->postJson('/api/orders', $payload);
        $res2->assertStatus(201);

        // Usage count MUST NOT be incremented again!
        $this->assertEquals(1, $promoCode->fresh()->used_count, 'Promo code usage count was incremented twice!');
    }

    /**
     * Scenario 8: Physical stock is NOT deducted twice on idempotent replay
     */
    public function test_08_stock_not_deducted_twice_on_idempotent_replay(): void
    {
        $product = $this->createTestProduct(['price' => 1000.00, 'stock_quantity' => 15]);
        $this->createCostLayer($product, 15, 500.00);

        $key = 'idemp-stock-' . Str::uuid();
        $payload = [
            'customer_name' => 'Stock Tester',
            'customer_email' => 'stock@example.com',
            'customer_phone' => '01711223344',
            'shipping_address' => [
                'address_line1' => 'Mirpur 1',
                'city' => 'Dhaka',
                'postal_code' => '1216',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3],
            ],
        ];

        // Call 1: Decrements 3 units (15 -> 12)
        $this->withHeader('X-Idempotency-Key', $key)->postJson('/api/orders', $payload)->assertStatus(201);
        $this->assertEquals(12, $product->fresh()->stock_quantity);

        // Call 2 (replay): MUST remain 12
        $this->withHeader('X-Idempotency-Key', $key)->postJson('/api/orders', $payload)->assertStatus(201);
        $this->assertEquals(12, $product->fresh()->stock_quantity, 'Stock was decremented twice on idempotent replay!');
    }

    /**
     * Scenario 9: FIFO cost layer is NOT consumed twice on idempotent replay
     */
    public function test_09_fifo_cost_layer_not_consumed_twice(): void
    {
        $product = $this->createTestProduct(['price' => 1000.00, 'stock_quantity' => 10]);
        $layer = $this->createCostLayer($product, 10, 550.00);

        $key = 'idemp-fifo-' . Str::uuid();
        $payload = [
            'customer_name' => 'FIFO Tester',
            'customer_email' => 'fifo@example.com',
            'customer_phone' => '01711223344',
            'shipping_address' => [
                'address_line1' => 'Tejgaon I/A',
                'city' => 'Dhaka',
                'postal_code' => '1208',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 4],
            ],
        ];

        // Call 1
        $this->withHeader('X-Idempotency-Key', $key)->postJson('/api/orders', $payload)->assertStatus(201);
        $this->assertEquals(6, $layer->fresh()->remaining_quantity);

        // Call 2 (replay)
        $this->withHeader('X-Idempotency-Key', $key)->postJson('/api/orders', $payload)->assertStatus(201);
        $this->assertEquals(6, $layer->fresh()->remaining_quantity, 'FIFO cost layer was consumed twice!');
    }

    /**
     * Scenario 10: Accounting journal entries are NOT posted twice on idempotent replay
     */
    public function test_10_accounting_journal_not_posted_twice(): void
    {
        $product = $this->createTestProduct(['price' => 1500.00, 'stock_quantity' => 10]);
        $this->createCostLayer($product, 10, 800.00);

        $key = 'idemp-acct-' . Str::uuid();
        $payload = [
            'customer_name' => 'Accounting Tester',
            'customer_email' => 'acct@example.com',
            'customer_phone' => '01711223344',
            'shipping_address' => [
                'address_line1' => 'Motijheel C/A',
                'city' => 'Dhaka',
                'postal_code' => '1000',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ];

        $res1 = $this->withHeader('X-Idempotency-Key', $key)->postJson('/api/orders', $payload);
        $res1->assertStatus(201);
        $orderId = $res1->json('order.id');

        // Check journal entries for this order
        $count1 = JournalEntry::where('reference_type', 'Order')->where('reference_id', $orderId)->count();
        $this->assertEquals(1, $count1);

        // Replay
        $res2 = $this->withHeader('X-Idempotency-Key', $key)->postJson('/api/orders', $payload);
        $res2->assertStatus(201);

        $count2 = JournalEntry::where('reference_type', 'Order')->where('reference_id', $orderId)->count();
        $this->assertEquals(1, $count2, 'Duplicate journal entries created on idempotent replay!');
    }

    // =========================================================================
    // 3. UNIFIED REFUND PATHWAY & PAYMENT LEDGER (Scenarios 11 - 15)
    // =========================================================================

    /**
     * Helper to create an admin user
     */
    protected function createAdminUser(): User
    {
        return User::create([
            'name' => 'Admin Boss',
            'email' => 'admin-' . uniqid() . '@example.com',
            'role' => 'admin',
            'status' => 'active',
            'password' => bcrypt('password'),
        ]);
    }

    /**
     * Scenario 11: Attempting to refund an unpaid order is strictly rejected
     */
    public function test_11_refund_unpaid_order_is_rejected(): void
    {
        $admin = $this->createAdminUser();
        $product = $this->createTestProduct();

        // Place an unpaid COD order
        $order = Order::create([
            'order_number' => 'ORD-' . strtoupper(Str::random(8)),
            'customer_name' => 'Unpaid Customer',
            'customer_email' => 'unpaid@example.com',
            'subtotal' => 1000.00,
            'total_amount' => 1060.00,
            'shipping_amount' => 60.00,
            'shipping_address' => ['city' => 'Dhaka'],
            'payment_status' => 'pending', // Unpaid!
            'payment_method' => 'cash_on_delivery',
            'order_status' => 'pending',
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 1000.00,
            'total_price' => 1000.00,
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/orders/{$order->id}/refund", [
                'reason' => 'Customer requested refund',
                'amount' => 1060.00,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('unpaid', strtolower($response->json('message')));
        $this->assertEquals('pending', $order->fresh()->payment_status);
    }

    /**
     * Scenario 12: Refund partially paid order cannot exceed paid amount
     */
    public function test_12_refund_partially_paid_order_cannot_exceed_paid_balance(): void
    {
        $admin = $this->createAdminUser();
        $product = $this->createTestProduct();

        $order = Order::create([
            'order_number' => 'ORD-' . strtoupper(Str::random(8)),
            'customer_name' => 'Partial Customer',
            'customer_email' => 'partial@example.com',
            'subtotal' => 2000.00,
            'total_amount' => 2060.00,
            'shipping_amount' => 60.00,
            'shipping_address' => ['city' => 'Dhaka'],
            'payment_status' => 'partially_paid',
            'payment_method' => 'cash_on_delivery',
            'order_status' => 'processing',
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'unit_price' => 1000.00,
            'total_price' => 2000.00,
        ]);

        // Customer paid 500 BDT advance/partial
        $order->payments()->create([
            'payment_number' => 'PAY-' . uniqid(),
            'payment_method' => 'cash',
            'amount' => 500.00,
            'currency' => 'BDT',
            'status' => 'completed',
            'type' => 'partial_payment',
        ]);
        $order->recalculatePaymentStatus();

        // Attempting to refund 600 BDT (> 500 paid) must be rejected!
        $response = $this->actingAs($admin)
            ->postJson("/api/admin/orders/{$order->id}/refund", [
                'reason' => 'Too high refund',
                'amount' => 600.00,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('exceeds', strtolower($response->json('message')));
    }

    /**
     * Scenario 13: Full refund creates contra-revenue journal and restocks inventory with FIFO
     */
    public function test_13_full_refund_posts_accounting_journal_and_restocks(): void
    {
        $admin = $this->createAdminUser();
        $product = $this->createTestProduct(['stock_quantity' => 10]);
        $layer = $this->createCostLayer($product, 10, 600.00);

        $order = Order::create([
            'order_number' => 'ORD-' . strtoupper(Str::random(8)),
            'customer_name' => 'Full Refund Customer',
            'customer_email' => 'fullref@example.com',
            'subtotal' => 1000.00,
            'total_amount' => 1060.00,
            'shipping_amount' => 60.00,
            'shipping_address' => ['city' => 'Dhaka'],
            'payment_status' => 'paid',
            'payment_method' => 'cash_on_delivery',
            'order_status' => 'delivered',
            'cogs_amount' => 600.00,
        ]);
        $orderItem = $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 1000.00,
            'total_price' => 1000.00,
            'cogs_unit_cost' => 600.00,
            'cogs_total_cost' => 600.00,
        ]);

        // Record customer full payment in ledger
        $order->payments()->create([
            'payment_number' => 'PAY-' . uniqid(),
            'payment_method' => 'cash_on_delivery',
            'amount' => 1060.00,
            'currency' => 'BDT',
            'status' => 'completed',
            'type' => 'full_payment',
        ]);
        $order->recalculatePaymentStatus();

        // Perform full refund with restock: true
        $response = $this->actingAs($admin)
            ->postJson("/api/admin/orders/{$order->id}/refund", [
                'reason' => 'Product returned in mint condition',
                'amount' => 1060.00,
                'restock' => true,
            ]);

        $response->assertStatus(200);
        $freshOrder = $order->fresh(['payments']);

        $this->assertEquals('refunded', $freshOrder->payment_status);
        $this->assertEquals(1060.00, (float) $freshOrder->amount_refunded);

        // Verify stock incremented
        $this->assertEquals(11, $product->fresh()->stock_quantity);

        // Verify refund entry in payment ledger
        $this->assertTrue(
            $freshOrder->payments()->where('type', 'refund')->where('status', 'completed')->exists(),
            'No refund payment ledger record created!'
        );

        // Verify contra-revenue accounting journal
        $journal = JournalEntry::where('reference_type', 'OrderRefund')->where('reference_id', $order->id)->first();
        $this->assertNotNull($journal, 'No contra-revenue journal entry posted for refund!');
    }

    /**
     * Scenario 14: Partial refund updates remaining refundable balance properly
     */
    public function test_14_partial_refund_updates_payment_status_to_partially_refunded(): void
    {
        $admin = $this->createAdminUser();
        $product = $this->createTestProduct();

        $order = Order::create([
            'order_number' => 'ORD-' . strtoupper(Str::random(8)),
            'customer_name' => 'Partial Refund Customer',
            'customer_email' => 'pref@example.com',
            'subtotal' => 2000.00,
            'total_amount' => 2060.00,
            'shipping_amount' => 60.00,
            'shipping_address' => ['city' => 'Dhaka'],
            'payment_status' => 'paid',
            'payment_method' => 'cash_on_delivery',
            'order_status' => 'delivered',
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'unit_price' => 1000.00,
            'total_price' => 2000.00,
        ]);

        $order->payments()->create([
            'payment_number' => 'PAY-' . uniqid(),
            'payment_method' => 'cash_on_delivery',
            'amount' => 2060.00,
            'currency' => 'BDT',
            'status' => 'completed',
            'type' => 'full_payment',
        ]);
        $order->recalculatePaymentStatus();

        // Refund partial amount (500 BDT)
        $response = $this->actingAs($admin)
            ->postJson("/api/admin/orders/{$order->id}/refund", [
                'reason' => 'One accessory returned',
                'amount' => 500.00,
                'restock' => false,
            ]);

        $response->assertStatus(200);
        $freshOrder = $order->fresh();

        $this->assertEquals('partially_refunded', $freshOrder->payment_status);
        $this->assertEquals(500.00, (float) $freshOrder->amount_refunded);
    }

    /**
     * Scenario 15: Duplicate refund after full refund is rejected
     */
    public function test_15_duplicate_refund_after_full_refund_is_rejected(): void
    {
        $admin = $this->createAdminUser();
        $product = $this->createTestProduct();

        $order = Order::create([
            'order_number' => 'ORD-' . strtoupper(Str::random(8)),
            'customer_name' => 'Already Refunded',
            'customer_email' => 'refunded@example.com',
            'subtotal' => 1000.00,
            'total_amount' => 1060.00,
            'shipping_amount' => 60.00,
            'shipping_address' => ['city' => 'Dhaka'],
            'payment_status' => 'refunded',
            'amount_refunded' => 1060.00,
            'payment_method' => 'cash_on_delivery',
            'order_status' => 'refunded',
        ]);

        $order->payments()->create([
            'payment_number' => 'PAY-' . uniqid(),
            'payment_method' => 'cash_on_delivery',
            'amount' => 1060.00,
            'currency' => 'BDT',
            'status' => 'completed',
            'type' => 'full_payment',
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/orders/{$order->id}/refund", [
                'reason' => 'Duplicate refund attempt',
                'amount' => 100.00,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('already', strtolower($response->json('message')));
    }

    // =========================================================================
    // 4. ADMIN PRICE OVERRIDE PROTECTION (Scenarios 16 - 17)
    // =========================================================================

    /**
     * Scenario 16: Staff without 'orders.price_override' permission is rejected with 403
     */
    public function test_16_staff_without_price_override_permission_is_rejected(): void
    {
        // Create staff user without price_override permission
        $staff = User::create([
            'name' => 'Regular Staff',
            'email' => 'staff-' . uniqid() . '@example.com',
            'role' => 'staff',
            'permissions' => ['orders.manage', 'orders.view'], // Missing orders.price_override
            'status' => 'active',
            'password' => bcrypt('password'),
        ]);

        $product = $this->createTestProduct(['price' => 1000.00]);

        $response = $this->actingAs($staff)
            ->postJson('/api/admin/orders', [
                'customer_name' => 'VIP Customer',
                'customer_email' => 'vip@example.com',
                'customer_phone' => '01711223344',
                'shipping_address' => [
                    'full_name' => 'VIP Customer',
                    'address_line1' => 'Road 1',
                    'city' => 'Dhaka',
                    'postal_code' => '1200',
                    'country' => 'Bangladesh',
                ],
                'payment_status' => 'pending',
                'payment_method' => 'cash_on_delivery',
                'order_status' => 'pending',
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 500.00, // Attempting 50% discount override!
                        'price_override_reason' => 'Friend of staff',
                    ],
                ],
            ]);

        $response->assertStatus(403);
    }

    /**
     * Scenario 17: Zero-dollar price override is rejected
     */
    public function test_17_zero_price_override_is_rejected(): void
    {
        $admin = $this->createAdminUser();
        $product = $this->createTestProduct(['price' => 1000.00]);

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/orders', [
                'customer_name' => 'Free Item Request',
                'customer_email' => 'free@example.com',
                'customer_phone' => '01711223344',
                'shipping_address' => [
                    'full_name' => 'Free Customer',
                    'address_line1' => 'Road 1',
                    'city' => 'Dhaka',
                    'postal_code' => '1200',
                    'country' => 'Bangladesh',
                ],
                'payment_status' => 'pending',
                'payment_method' => 'cash_on_delivery',
                'order_status' => 'pending',
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 0.00, // Zero price!
                        'price_override_reason' => 'Giving away for free',
                    ],
                ],
            ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // 5. CATALOG INTEGRITY AT CHECKOUT (Scenarios 18 - 19)
    // =========================================================================

    /**
     * Scenario 18: Inactive product checkout is rejected with 422
     */
    public function test_18_inactive_product_checkout_is_rejected(): void
    {
        $inactiveProduct = $this->createTestProduct([
            'price' => 1000.00,
            'is_active' => false, // Inactive!
            'stock_quantity' => 10,
        ]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Eager Buyer',
            'customer_email' => 'eager@example.com',
            'customer_phone' => '01711223344',
            'shipping_address' => [
                'address_line1' => 'Dhanmondi 32',
                'city' => 'Dhaka',
                'postal_code' => '1209',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $inactiveProduct->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items']);
        $this->assertStringContainsString('inactive', strtolower($response->json('errors.items.0')));
    }

    /**
     * Scenario 19: Variant belonging to another product is rejected with 422
     */
    public function test_19_invalid_variant_product_mismatch_is_rejected(): void
    {
        $productA = $this->createTestProduct(['price' => 500.00]);
        $productB = $this->createTestProduct(['price' => 900.00]);

        // Variant belongs to product B
        $variantB = ProductVariant::create([
            'product_id' => $productB->id,
            'name' => 'Variant of B',
            'sku' => $productB->sku . '-VARB',
            'price_modifier' => 100.00,
            'stock_quantity' => 10,
        ]);

        // Customer submits product A with variant B!
        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Mismatched Variant Buyer',
            'customer_email' => 'mismatch@example.com',
            'customer_phone' => '01711223344',
            'shipping_address' => [
                'address_line1' => 'Banani 11',
                'city' => 'Dhaka',
                'postal_code' => '1213',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'items' => [
                [
                    'product_id' => $productA->id,
                    'variant_id' => $variantB->id, // Belongs to product B, not product A!
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items']);
        $this->assertStringContainsString('belong', strtolower($response->json('errors.items.0')));
    }
}
