<?php

namespace Tests\Feature\Payment;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Payment\PaymentManager;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentArchitectureTest extends TestCase
{
    protected function createProduct(array $overrides = []): Product
    {
        $category = Category::firstOrCreate(['slug' => 'audio-gear'], ['name' => 'Audio Gear']);

        return Product::create(array_merge([
            'name' => 'Studio Monitor Pro ' . uniqid(),
            'slug' => 'studio-monitor-' . uniqid(),
            'sku' => 'MON-' . strtoupper(Str::random(6)),
            'price' => 1200.00,
            'cost_price' => 700.00,
            'stock_quantity' => 25,
            'is_active' => true,
            'description' => 'Professional studio monitor.',
            'category_id' => $category->id,
        ], $overrides));
    }

    protected function createVariant(Product $product, array $overrides = []): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'name' => 'Walnut Finish',
            'sku' => $product->sku . '-WLN',
            'price_modifier' => 150.00,
            'cost_price' => 750.00,
            'stock_quantity' => 10,
        ], $overrides));
    }

    // =========================================================================
    // SECTION 1: CASH ON DELIVERY (COD) CORE FLOWS (Tests 1 - 15)
    // =========================================================================

    /**
     * 1. Guest can place COD order.
     */
    public function test_01_guest_can_place_cod_order(): void
    {
        $product = $this->createProduct(['price' => 500.00, 'stock_quantity' => 10]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Rahim Guest',
            'customer_email' => 'rahim.guest@example.com',
            'customer_phone' => '01711000111',
            'shipping_address' => [
                'address_line1' => 'Road 5, Dhanmondi',
                'city' => 'Dhaka',
                'postal_code' => '1209',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(201);
        $order = $response->json('order');

        $this->assertNotEmpty($order['order_number']);
        $this->assertEquals('Rahim Guest', $order['customer_name']);
        $this->assertEquals('rahim.guest@example.com', $order['customer_email']);
        $this->assertEquals('cash_on_delivery', $order['payment_method']);
        $this->assertEquals('pending', $order['payment_status']);
    }

    /**
     * 2. Authenticated customer can place COD order and order is linked to user.
     */
    public function test_02_authenticated_customer_can_place_cod_order(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $product = $this->createProduct(['price' => 450.00, 'stock_quantity' => 15]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/orders', [
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'customer_phone' => '01811223344',
            'shipping_address' => [
                'address_line1' => 'Plot 12, Gulshan 1',
                'city' => 'Dhaka',
                'postal_code' => '1212',
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

        $this->assertEquals($user->id, $order['user_id']);
        $this->assertEquals('cash_on_delivery', $order['payment_method']);
        $this->assertEquals('pending', $order['payment_status']);
    }

    /**
     * 3. Correct payment method stored: 'cod' alias is normalized to canonical 'cash_on_delivery'.
     */
    public function test_03_cod_alias_normalizes_to_canonical_cash_on_delivery(): void
    {
        $product = $this->createProduct(['price' => 300.00]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Alias User',
            'customer_email' => 'alias@example.com',
            'customer_phone' => '01911223344',
            'shipping_address' => [
                'address_line1' => 'Uttara Sector 3',
                'city' => 'Dhaka',
                'postal_code' => '1230',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cod', // Alias!
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);
        $orderData = $response->json('order');

        $this->assertEquals('cash_on_delivery', $orderData['payment_method'], 'Payment method must be normalized to canonical cash_on_delivery.');

        $orderDb = Order::where('order_number', $orderData['order_number'])->firstOrFail();
        $this->assertEquals('cash_on_delivery', $orderDb->payment_method);
    }

    /**
     * 4. Correct initial payment status stored: strictly 'pending', not 'paid'.
     */
    public function test_04_correct_initial_payment_status_is_pending(): void
    {
        $product = $this->createProduct(['price' => 400.00]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Status Check',
            'customer_email' => 'status@example.com',
            'customer_phone' => '01711223355',
            'shipping_address' => [
                'address_line1' => 'Mirpur 14',
                'city' => 'Dhaka',
                'postal_code' => '1216',
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

        $this->assertEquals('pending', $order['payment_status']);
        $this->assertNull($order['payment_transaction_id']);

        // Verify payment ledger record
        $orderDb = Order::where('order_number', $order['order_number'])->firstOrFail();
        $this->assertCount(1, $orderDb->payments);
        $paymentRecord = $orderDb->payments->first();
        $this->assertEquals('pending', $paymentRecord->status);
        $this->assertEquals('collection', $paymentRecord->type);
        $this->assertEquals('cash_on_delivery', $paymentRecord->payment_method);
    }

    /**
     * 5. Server calculates authoritative total (subtotal + shipping).
     */
    public function test_05_server_calculates_authoritative_total(): void
    {
        $product = $this->createProduct(['price' => 750.00]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Math Verification',
            'customer_email' => 'math@example.com',
            'customer_phone' => '01711223366',
            'shipping_address' => [
                'address_line1' => 'Agrabad C/A',
                'city' => 'Chittagong',
                'postal_code' => '4000',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'outside_dhaka', // 120.00 BDT
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2], // 1500.00 BDT
            ],
        ]);

        $response->assertStatus(201);
        $order = $response->json('order');

        $expectedShipping = (float) (collect(\App\Services\ShippingZoneResolver::getConfiguredZones())->firstWhere('id', 'outside_dhaka')['rate'] ?? 130.00);
        $this->assertEquals(1500.00, (float) $order['subtotal']);
        $this->assertEquals($expectedShipping, (float) $order['shipping_amount']);
        $this->assertEquals(1500.00 + $expectedShipping, (float) $order['total_amount']);
    }

    /**
     * 6. Client cannot manipulate price: payload unit_price is ignored.
     */
    public function test_06_client_cannot_manipulate_item_price(): void
    {
        $product = $this->createProduct(['price' => 1000.00]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Cheating Client',
            'customer_email' => 'cheat@example.com',
            'customer_phone' => '01711223377',
            'shipping_address' => [
                'address_line1' => 'Dhanmondi',
                'city' => 'Dhaka',
                'postal_code' => '1209',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 1.00, // Attacker tries to pay 1 BDT instead of 1000 BDT!
                    'price' => 1.00,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $order = $response->json('order');

        $this->assertEquals(1000.00, (float) $order['subtotal'], 'Server must use authoritative DB product price.');
        $this->assertEquals(1060.00, (float) $order['total_amount'], 'Total must include real product price + shipping.');
    }

    /**
     * 7. Client cannot manipulate total: payload total_amount is ignored.
     */
    public function test_07_client_cannot_manipulate_final_total(): void
    {
        $product = $this->createProduct(['price' => 800.00]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Cheating Total',
            'customer_email' => 'cheat.total@example.com',
            'customer_phone' => '01711223388',
            'shipping_address' => [
                'address_line1' => 'Banani',
                'city' => 'Dhaka',
                'postal_code' => '1213',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'total_amount' => 5.00, // Attacker sends fake total!
            'subtotal' => 5.00,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);
        $order = $response->json('order');

        $this->assertEquals(860.00, (float) $order['total_amount']);
    }

    /**
     * 8. Client cannot mark payment as paid.
     */
    public function test_08_client_cannot_mark_payment_as_paid(): void
    {
        $product = $this->createProduct(['price' => 600.00]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Fake Paid',
            'customer_email' => 'fake.paid@example.com',
            'customer_phone' => '01711223399',
            'shipping_address' => [
                'address_line1' => 'Gulshan',
                'city' => 'Dhaka',
                'postal_code' => '1212',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'paid', // Attacker claims they already paid!
            'status' => 'paid',
            'payment_transaction_id' => 'TXN-FAKE-12345',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);
        $order = $response->json('order');

        $this->assertEquals('pending', $order['payment_status'], 'COD payment status must always initialize as pending.');
        $this->assertNull($order['payment_transaction_id']);
    }

    /**
     * 9. Stock is correctly handled (inventory decrements accurately).
     */
    public function test_09_stock_is_correctly_decremented_on_cod_checkout(): void
    {
        $product = $this->createProduct(['price' => 250.00, 'stock_quantity' => 20]);
        $variant = $this->createVariant($product, ['stock_quantity' => 8]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Stock Buyer',
            'customer_email' => 'stock.buyer@example.com',
            'customer_phone' => '01711223300',
            'shipping_address' => [
                'address_line1' => 'Badda',
                'city' => 'Dhaka',
                'postal_code' => '1212',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 3],
            ],
        ]);

        $response->assertStatus(201);

        $this->assertEquals(17, $product->fresh()->stock_quantity);
        $this->assertEquals(5, $variant->fresh()->stock_quantity);
    }

    /**
     * 10. Invalid/stale cart exceeding stock is rejected with 422.
     */
    public function test_10_cart_exceeding_stock_is_rejected(): void
    {
        $product = $this->createProduct(['price' => 500.00, 'stock_quantity' => 2]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Over Buyer',
            'customer_email' => 'over@example.com',
            'customer_phone' => '01711223311',
            'shipping_address' => [
                'address_line1' => 'Tejgaon',
                'city' => 'Dhaka',
                'postal_code' => '1208',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5], // Only 2 in stock!
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items']);
        $this->assertStringContainsString('Insufficient inventory', $response->json('errors.items.0'));
    }

    /**
     * 11. Inactive product is rejected with 422.
     */
    public function test_11_inactive_product_is_rejected(): void
    {
        $inactiveProduct = $this->createProduct(['price' => 500.00, 'is_active' => false]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Inactive Test',
            'customer_email' => 'inactive@example.com',
            'customer_phone' => '01711223322',
            'shipping_address' => [
                'address_line1' => 'Mohakhali',
                'city' => 'Dhaka',
                'postal_code' => '1212',
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
     * 12. Invalid variant belonging to another product is rejected with 422.
     */
    public function test_12_invalid_variant_mismatch_is_rejected(): void
    {
        $productA = $this->createProduct(['price' => 500.00]);
        $productB = $this->createProduct(['price' => 900.00]);
        $variantB = $this->createVariant($productB);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Mismatch Buyer',
            'customer_email' => 'mismatch@example.com',
            'customer_phone' => '01711223333',
            'shipping_address' => [
                'address_line1' => 'Baridhara',
                'city' => 'Dhaka',
                'postal_code' => '1212',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $productA->id, 'variant_id' => $variantB->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items']);
        $this->assertStringContainsString('belong', strtolower($response->json('errors.items.0')));
    }

    /**
     * 13. Coupon/promotion rules remain correct on COD order.
     */
    public function test_13_coupon_discount_is_applied_accurately(): void
    {
        $promotion = \App\Models\Promotion::create([
            'name' => 'Discount 100 Promo',
            'slug' => 'disc-100-' . uniqid(),
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
            'code' => 'DISC100_' . strtoupper(Str::random(4)),
            'usage_limit' => 10,
            'used_count' => 0,
            'is_active' => true,
        ]);

        $product = $this->createProduct(['price' => 800.00]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Coupon User',
            'customer_email' => 'coupon@example.com',
            'customer_phone' => '01711223344',
            'shipping_address' => [
                'address_line1' => 'Mirpur DOHS',
                'city' => 'Dhaka',
                'postal_code' => '1216',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka', // 60.00 BDT
            'payment_method' => 'cash_on_delivery',
            'coupon_code' => $promoCode->code,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);
        $order = $response->json('order');

        $this->assertEquals(800.00, (float) $order['subtotal']);
        $this->assertEquals(100.00, (float) $order['discount_amount']);
        $this->assertEquals(60.00, (float) $order['shipping_amount']);
        $this->assertEquals(760.00, (float) $order['total_amount']); // 800 - 100 + 60
    }

    /**
     * 14. Shipping calculation remains correct across zones.
     */
    public function test_14_shipping_rate_calculates_correctly_for_outside_dhaka(): void
    {
        $product = $this->createProduct(['price' => 400.00]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Sylhet Buyer',
            'customer_email' => 'sylhet@example.com',
            'customer_phone' => '01711223355',
            'shipping_address' => [
                'address_line1' => 'Zindabazar',
                'city' => 'Sylhet',
                'postal_code' => '3100',
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

        $expectedShipping = (float) (collect(\App\Services\ShippingZoneResolver::getConfiguredZones())->firstWhere('id', 'outside_dhaka')['rate'] ?? 130.00);
        $this->assertEquals($expectedShipping, (float) $order['shipping_amount']);
        $this->assertEquals(400.00 + $expectedShipping, (float) $order['total_amount']);
    }

    /**
     * 15. Order confirmation data is complete and accurate.
     */
    public function test_15_order_confirmation_data_is_accurate(): void
    {
        $product = $this->createProduct(['price' => 600.00]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Confirm Customer',
            'customer_email' => 'confirm@example.com',
            'customer_phone' => '01711223366',
            'shipping_address' => [
                'address_line1' => 'Sector 4, Uttara',
                'city' => 'Dhaka',
                'postal_code' => '1230',
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

        $this->assertArrayHasKey('order_number', $order);
        $this->assertArrayHasKey('customer_name', $order);
        $this->assertArrayHasKey('customer_email', $order);
        $this->assertArrayHasKey('shipping_address', $order);
        $this->assertArrayHasKey('payment_method', $order);
        $this->assertArrayHasKey('payment_status', $order);
        $this->assertArrayHasKey('items', $order);
        $this->assertArrayHasKey('subtotal', $order);
        $this->assertArrayHasKey('total_amount', $order);
    }

    // =========================================================================
    // SECTION 2: ONLINE PAYMENT NOT CONFIGURED BEHAVIOR (Tests 16 - 20)
    // =========================================================================

    /**
     * 16. Online payment cannot be selected when unavailable (reported via /payment-methods).
     */
    public function test_16_public_payment_methods_shows_online_payment_as_unavailable(): void
    {
        $response = $this->getJson('/api/payment-methods');

        $response->assertStatus(200);
        $methods = $response->json('payment_methods');

        $this->assertIsArray($methods);

        // Find COD
        $cod = collect($methods)->firstWhere('id', 'cash_on_delivery');
        $this->assertNotNull($cod);
        $this->assertTrue($cod['is_available']);

        // Find Online
        $online = collect($methods)->firstWhere('id', 'online');
        $this->assertNotNull($online);
        $this->assertFalse($online['is_available'], 'Online payment must be marked unavailable until real credentials are provided.');
        $this->assertStringContainsString('unavailable', strtolower($online['description']));
    }

    /**
     * 17. Backend rejects unconfigured online payment initiation with safe 422 error.
     */
    public function test_17_backend_rejects_unconfigured_online_payment_initiation(): void
    {
        $product = $this->createProduct(['price' => 500.00]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Card Customer',
            'customer_email' => 'card@example.com',
            'customer_phone' => '01711223377',
            'shipping_address' => [
                'address_line1' => 'Banani Road 11',
                'city' => 'Dhaka',
                'postal_code' => '1213',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'credit_card', // Online payment attempt
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payment_method']);
        $this->assertStringContainsString('Online payment is currently unavailable', $response->json('errors.payment_method.0'));
    }

    /**
     * 18. No order is created or incorrectly marked paid on unconfigured online payment attempt.
     */
    public function test_18_no_order_is_created_or_marked_paid_on_failed_online_payment(): void
    {
        $initialOrderCount = Order::count();
        $product = $this->createProduct(['price' => 500.00]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Fake Gateway Attempt',
            'customer_email' => 'fake.gateway@example.com',
            'customer_phone' => '01711223388',
            'shipping_address' => [
                'address_line1' => 'Gulshan 2',
                'city' => 'Dhaka',
                'postal_code' => '1212',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'online',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertEquals($initialOrderCount, Order::count(), 'No order should be created when online payment is unconfigured.');
    }

    /**
     * 19. No secrets appear in API responses or errors.
     */
    public function test_19_no_secrets_appear_in_api_responses(): void
    {
        $response = $this->getJson('/api/payment-methods');
        $response->assertStatus(200);

        $jsonString = $response->getContent();
        $this->assertStringNotContainsString('api_key', strtolower($jsonString));
        $this->assertStringNotContainsString('secret', strtolower($jsonString));
        $this->assertStringNotContainsString('store_password', strtolower($jsonString));
        $this->assertStringNotContainsString('store_id', strtolower($jsonString));
    }

    /**
     * 20. No raw card data is accepted or stored.
     */
    public function test_20_no_raw_card_data_is_stored(): void
    {
        $product = $this->createProduct(['price' => 500.00]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Card Attempt',
            'customer_email' => 'card.attempt@example.com',
            'customer_phone' => '01711223399',
            'shipping_address' => [
                'address_line1' => 'Dhanmondi',
                'city' => 'Dhaka',
                'postal_code' => '1209',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'credit_card',
            'card_number' => '4111222233334444',
            'cvv' => '123',
            'expiry' => '12/28',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);

        // Verify database contains zero orders with card data
        $this->assertDatabaseMissing('orders', [
            'customer_email' => 'card.attempt@example.com',
        ]);
        $this->assertDatabaseMissing('order_payments', [
            'notes' => '4111222233334444',
        ]);
    }

    // =========================================================================
    // SECTION 3: SECURITY & AUTHORITATIVE INTEGRITY (Tests 21 - 24)
    // =========================================================================

    /**
     * 21. Payment status cannot be manipulated from client request.
     */
    public function test_21_payment_status_cannot_be_manipulated_by_client(): void
    {
        $product = $this->createProduct(['price' => 350.00]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Tamper Status',
            'customer_email' => 'tamper.status@example.com',
            'customer_phone' => '01711223300',
            'shipping_address' => [
                'address_line1' => 'Lalmatia',
                'city' => 'Dhaka',
                'postal_code' => '1207',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'paid', // Tampered input!
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);
        $order = $response->json('order');

        $this->assertEquals('pending', $order['payment_status']);
    }

    /**
     * 22. Payment method cannot be changed to an unauthorized/unknown value.
     */
    public function test_22_payment_method_cannot_be_unauthorized_value(): void
    {
        $product = $this->createProduct(['price' => 350.00]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Crypto Guy',
            'customer_email' => 'crypto@example.com',
            'customer_phone' => '01711223311',
            'shipping_address' => [
                'address_line1' => 'Mohammadpur',
                'city' => 'Dhaka',
                'postal_code' => '1207',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'bitcoin_unauthorized',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payment_method']);
    }

    /**
     * 23. Final total cannot be supplied by the client.
     */
    public function test_23_final_total_cannot_be_supplied_by_client(): void
    {
        $product = $this->createProduct(['price' => 1500.00]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Discount Forger',
            'customer_email' => 'forger@example.com',
            'customer_phone' => '01711223322',
            'shipping_address' => [
                'address_line1' => 'Banasree',
                'city' => 'Dhaka',
                'postal_code' => '1219',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'total_amount' => 10.00,
            'discount_amount' => 1490.00, // Fabricated discount without coupon!
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);
        $order = $response->json('order');

        $this->assertEquals(0.00, (float) $order['discount_amount'], 'Fabricated discount must be ignored.');
        $this->assertEquals(1560.00, (float) $order['total_amount'], 'Total must be authoritative subtotal + shipping.');
    }

    /**
     * 24. Sensitive product cost and financial margin fields remain hidden.
     */
    public function test_24_sensitive_cost_and_profit_fields_remain_hidden(): void
    {
        $product = $this->createProduct(['price' => 800.00, 'cost_price' => 500.00]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Privacy Checker',
            'customer_email' => 'privacy@example.com',
            'customer_phone' => '01711223333',
            'shipping_address' => [
                'address_line1' => 'Nikunja',
                'city' => 'Dhaka',
                'postal_code' => '1229',
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

        // Order-level hidden fields
        $this->assertArrayNotHasKey('cogs_amount', $order);
        $this->assertArrayNotHasKey('gross_profit', $order);

        // Item-level hidden fields
        $firstItem = $order['items'][0];
        $this->assertArrayNotHasKey('cogs_unit_cost', $firstItem);
        $this->assertArrayNotHasKey('cogs_total', $firstItem);
        $this->assertArrayNotHasKey('gross_profit', $firstItem);
    }
}
