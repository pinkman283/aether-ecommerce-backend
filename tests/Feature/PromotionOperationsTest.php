<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionCode;
use App\Models\PromotionProductTarget;
use App\Models\PromotionRedemption;
use App\Models\User;
use App\Services\PromotionEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PromotionOperationsTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected User $customerUser;
    protected Product $activeProduct;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        \Artisan::call('db:seed', ['--class' => 'ChartOfAccountsSeeder']);

        $this->adminUser = User::factory()->create([
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->customerUser = User::factory()->create([
            'role' => 'customer',
            'status' => 'active',
            'email' => 'customer_' . Str::random(8) . '@example.com',
        ]);

        $this->category = Category::create([
            'name' => 'Fashion ' . Str::random(5),
            'slug' => 'fashion-' . Str::random(8),
            'is_active' => true,
        ]);

        $this->activeProduct = Product::create([
            'name' => 'Cotton Shirt ' . Str::random(5),
            'slug' => 'cotton-shirt-' . Str::random(8),
            'sku' => 'SHIRT-' . strtoupper(Str::random(6)),
            'category_id' => $this->category->id,
            'price' => 1000.00,
            'cost_price' => 600.00,
            'stock_quantity' => 50,
            'is_active' => true,
        ]);
    }

    /**
     * 1. Test valid PromotionCode via PromotionEngine
     */
    public function test_valid_promotion_code_evaluation(): void
    {
        $promo = Promotion::create([
            'name' => 'Summer Sale 20%',
            'slug' => 'summer-sale-' . Str::random(6),
            'promotion_type' => 'discount_code',
            'discount_type' => 'percentage',
            'discount_value' => 20.00,
            'applies_to' => 'entire_order',
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDays(7),
        ]);

        $code = 'SUMMER' . strtoupper(Str::random(4));
        PromotionCode::create([
            'promotion_id' => $promo->id,
            'code' => $code,
            'is_active' => true,
        ]);

        $items = [
            ['product_id' => $this->activeProduct->id, 'quantity' => 2, 'unit_price' => 1000.00],
        ];

        $result = PromotionEngine::evaluateCart($items, $this->customerUser, $this->customerUser->email, $code);

        $this->assertTrue($result['valid']);
        $this->assertEquals(2000.00, $result['subtotal']);
        $this->assertEquals(400.00, $result['order_discount']); // 20% of 2000
        $this->assertEquals(1600.00, $result['grand_total']);
        $this->assertCount(1, $result['applied_promotions']);
    }

    /**
     * 2. Test valid legacy / admin Coupon model via PromotionEngine bridge
     */
    public function test_valid_legacy_coupon_evaluation(): void
    {
        $code = 'WELCOME' . strtoupper(Str::random(4));
        $coupon = Coupon::create([
            'code' => $code,
            'type' => 'fixed',
            'value' => 150.00,
            'min_order_amount' => 500.00,
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDays(10),
        ]);

        $items = [
            ['product_id' => $this->activeProduct->id, 'quantity' => 1, 'unit_price' => 1000.00],
        ];

        $result = PromotionEngine::evaluateCart($items, $this->customerUser, $this->customerUser->email, $code);

        $this->assertTrue($result['valid']);
        $this->assertEquals(1000.00, $result['subtotal']);
        $this->assertEquals(150.00, $result['order_discount']);
        $this->assertEquals(850.00, $result['grand_total']);
        $this->assertCount(1, $result['applied_promotions']);
    }

    /**
     * 3. Test invalid coupon code returns 404 on validation endpoint
     */
    public function test_invalid_coupon_code_rejected(): void
    {
        $response = $this->postJson('/api/coupons/validate', [
            'code' => 'NONEXISTENT_CODE_XYZ',
            'subtotal' => 1000.00,
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'valid' => false,
                'error_type' => 'invalid_coupon',
            ]);
    }

    /**
     * 4. Test inactive coupon is rejected with 422
     */
    public function test_inactive_coupon_rejected(): void
    {
        $code = 'INACTIVE' . strtoupper(Str::random(4));
        Coupon::create([
            'code' => $code,
            'type' => 'percentage',
            'value' => 10.00,
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/coupons/validate', [
            'code' => $code,
            'subtotal' => 1000.00,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'valid' => false,
                'error_type' => 'inactive_coupon',
            ]);
    }

    /**
     * 5. Test future coupon is rejected before campaign starts
     */
    public function test_future_coupon_rejected(): void
    {
        $code = 'FUTURE' . strtoupper(Str::random(4));
        Coupon::create([
            'code' => $code,
            'type' => 'fixed',
            'value' => 100.00,
            'is_active' => true,
            'starts_at' => now()->addDays(5),
            'expires_at' => now()->addDays(15),
        ]);

        $response = $this->postJson('/api/coupons/validate', [
            'code' => $code,
            'subtotal' => 1000.00,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'valid' => false,
                'error_type' => 'coupon_not_started',
            ]);
    }

    /**
     * 6. Test expired coupon is rejected
     */
    public function test_expired_coupon_rejected(): void
    {
        $code = 'EXPIRED' . strtoupper(Str::random(4));
        Coupon::create([
            'code' => $code,
            'type' => 'fixed',
            'value' => 100.00,
            'is_active' => true,
            'starts_at' => now()->subDays(10),
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->postJson('/api/coupons/validate', [
            'code' => $code,
            'subtotal' => 1000.00,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'valid' => false,
                'error_type' => 'expired_coupon',
            ]);
    }

    /**
     * 7. Test usage limit reached is rejected
     */
    public function test_usage_limit_reached_rejected(): void
    {
        $code = 'MAXUSED' . strtoupper(Str::random(4));
        Coupon::create([
            'code' => $code,
            'type' => 'fixed',
            'value' => 50.00,
            'usage_limit' => 5,
            'used_count' => 5,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/coupons/validate', [
            'code' => $code,
            'subtotal' => 1000.00,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'valid' => false,
                'error_type' => 'usage_limit_reached',
            ]);
    }

    /**
     * 8. Test minimum order amount requirement
     */
    public function test_minimum_order_amount_enforced(): void
    {
        $code = 'MINORDER' . strtoupper(Str::random(4));
        Coupon::create([
            'code' => $code,
            'type' => 'fixed',
            'value' => 100.00,
            'min_order_amount' => 1500.00,
            'is_active' => true,
        ]);

        // Fails when subtotal is 1000 (< 1500)
        $failResponse = $this->postJson('/api/coupons/validate', [
            'code' => $code,
            'subtotal' => 1000.00,
        ]);

        $failResponse->assertStatus(422)
            ->assertJson([
                'valid' => false,
                'error_type' => 'minimum_order_not_met',
            ]);

        // Passes when subtotal is 2000 (>= 1500)
        $passResponse = $this->postJson('/api/coupons/validate', [
            'code' => $code,
            'subtotal' => 2000.00,
        ]);

        $passResponse->assertStatus(200)
            ->assertJson([
                'valid' => true,
                'discount_amount' => 100.00,
            ]);
    }

    /**
     * 9. Test percentage discount with maximum discount cap
     */
    public function test_percentage_discount_with_max_discount_cap(): void
    {
        $code = 'CAPTEST' . strtoupper(Str::random(4));
        Coupon::create([
            'code' => $code,
            'type' => 'percentage',
            'value' => 50.00, // 50%
            'max_discount_amount' => 200.00, // Capped at 200
            'is_active' => true,
        ]);

        // 50% of 1000 is 500, but capped at 200
        $response = $this->postJson('/api/coupons/validate', [
            'code' => $code,
            'subtotal' => 1000.00,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'valid' => true,
                'discount_amount' => 200.00,
            ]);
    }

    /**
     * 10. Test discount cannot produce negative order total
     */
    public function test_discount_cannot_produce_negative_total(): void
    {
        $code = 'HUGE' . strtoupper(Str::random(4));
        $coupon = Coupon::create([
            'code' => $code,
            'type' => 'fixed',
            'value' => 5000.00, // Exceeds product price of 1000
            'is_active' => true,
        ]);

        $items = [
            ['product_id' => $this->activeProduct->id, 'quantity' => 1, 'unit_price' => 1000.00],
        ];

        $result = PromotionEngine::evaluateCart($items, $this->customerUser, $this->customerUser->email, $code);

        $this->assertTrue($result['valid']);
        $this->assertEquals(1000.00, $result['subtotal']);
        $this->assertEquals(1000.00, $result['order_discount']); // Capped at subtotal
        $this->assertEquals(0.00, $result['grand_total']);
        $this->assertGreaterThanOrEqual(0.00, $result['grand_total']);
    }

    /**
     * 11. Test product / category targeting eligibility
     */
    public function test_product_and_category_targeting(): void
    {
        $otherCategory = Category::create([
            'name' => 'Electronics ' . Str::random(5),
            'slug' => 'electronics-' . Str::random(8),
            'is_active' => true,
        ]);

        $ineligibleProduct = Product::create([
            'name' => 'USB Cable ' . Str::random(5),
            'slug' => 'usb-cable-' . Str::random(8),
            'sku' => 'USB-' . strtoupper(Str::random(6)),
            'category_id' => $otherCategory->id,
            'price' => 300.00,
            'stock_quantity' => 20,
            'is_active' => true,
        ]);

        // Promotion targeting only Fashion category
        $promo = Promotion::create([
            'name' => 'Fashion Category 10% Off',
            'slug' => 'fashion-promo-' . Str::random(6),
            'promotion_type' => 'discount_code',
            'discount_type' => 'product_percentage_discount',
            'discount_value' => 10.00,
            'applies_to' => 'specific_categories',
            'status' => 'active',
        ]);

        PromotionProductTarget::create([
            'promotion_id' => $promo->id,
            'target_type' => 'category',
            'target_id' => $this->category->id,
            'is_exclusion' => false,
        ]);

        $code = 'FASHION' . strtoupper(Str::random(4));
        PromotionCode::create([
            'promotion_id' => $promo->id,
            'code' => $code,
            'is_active' => true,
        ]);

        // Cart with only ineligible items -> fails
        $ineligibleItems = [
            ['product_id' => $ineligibleProduct->id, 'quantity' => 1, 'unit_price' => 300.00],
        ];
        $failResult = PromotionEngine::evaluateCart($ineligibleItems, $this->customerUser, $this->customerUser->email, $code);
        $this->assertFalse($failResult['valid']);

        // Cart with mixed items -> applies 10% only to eligible item (10% of 1000 = 100, not 10% of 1300)
        $mixedItems = [
            ['product_id' => $this->activeProduct->id, 'quantity' => 1, 'unit_price' => 1000.00],
            ['product_id' => $ineligibleProduct->id, 'quantity' => 1, 'unit_price' => 300.00],
        ];
        $mixedResult = PromotionEngine::evaluateCart($mixedItems, $this->customerUser, $this->customerUser->email, $code);
        $this->assertTrue($mixedResult['valid']);
        $this->assertEquals(1300.00, $mixedResult['subtotal']);
        $this->assertEquals(100.00, $mixedResult['order_discount']); // Only 100 off 1000 eligible item
    }

    /**
     * 12. Test Cart Validation endpoint integrates with coupons
     */
    public function test_cart_validation_endpoint_with_coupon(): void
    {
        $code = 'CARTVALID' . strtoupper(Str::random(4));
        Coupon::create([
            'code' => $code,
            'type' => 'fixed',
            'value' => 120.00,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                ['product_id' => $this->activeProduct->id, 'quantity' => 1, 'price' => 1000.00],
            ],
            'coupon_code' => $code,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'is_valid' => true,
                'coupon' => [
                    'valid' => true,
                    'code' => $code,
                    'discount_amount' => 120.00,
                ],
                'summary' => [
                    'subtotal' => 1000.00,
                    'discount_amount' => 120.00,
                    'total' => 880.00,
                ],
            ]);
    }

    /**
     * 13. Test Cart Validation rejects expired coupon and returns notice
     */
    public function test_cart_validation_rejects_expired_coupon(): void
    {
        $code = 'STALE' . strtoupper(Str::random(4));
        Coupon::create([
            'code' => $code,
            'type' => 'fixed',
            'value' => 100.00,
            'is_active' => true,
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                ['product_id' => $this->activeProduct->id, 'quantity' => 1, 'price' => 1000.00],
            ],
            'coupon_code' => $code,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'has_changes' => true,
                'coupon' => [
                    'valid' => false,
                    'discount_amount' => 0.00,
                ],
            ]);

        $this->assertNotEmpty($response->json('summary.notices'));
    }

    /**
     * 14. Test checkout uses server-authoritative calculations and preserves order history
     */
    public function test_checkout_applies_authoritative_discount_and_records_redemption(): void
    {
        $code = 'ORDERTEST' . strtoupper(Str::random(4));
        $coupon = Coupon::create([
            'code' => $code,
            'type' => 'percentage',
            'value' => 10.00,
            'usage_limit' => 10,
            'used_count' => 0,
            'is_active' => true,
        ]);

        $orderPayload = [
            'customer_name' => 'Test Customer',
            'customer_email' => 'ordertest_' . Str::random(5) . '@example.com',
            'customer_phone' => '01711000000',
            'shipping_address' => [
                'full_name' => 'Test Customer',
                'address_line1' => 'Dhanmondi, Road 27',
                'city' => 'Dhaka',
                'district' => 'Dhaka',
                'postal_code' => '1209',
                'country' => 'Bangladesh',
                'phone' => '01711000000',
            ],
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'inside_dhaka',
            'coupon_code' => $code,
            'items' => [
                ['product_id' => $this->activeProduct->id, 'quantity' => 2], // 2 x 1000 = 2000
            ],
        ];

        $response = $this->postJson('/api/orders', $orderPayload);

        $response->assertStatus(201);
        $orderData = $response->json('order');

        $this->assertEquals(2000.00, (float) $orderData['subtotal']);
        $this->assertEquals(200.00, (float) $orderData['discount_amount']); // 10% of 2000
        $this->assertEquals($code, $orderData['coupon_code']);

        // Verify coupon used_count incremented
        $coupon->refresh();
        $this->assertEquals(1, $coupon->used_count);

        // Verify PromotionRedemption audit record created
        $redemption = PromotionRedemption::where('order_id', $orderData['id'])->first();
        $this->assertNotNull($redemption);
        $this->assertEquals(200.00, (float) $redemption->discount_amount);
        $this->assertEquals('completed', $redemption->status);

        // Historical order protection: altering coupon later does not affect created order
        $coupon->update(['value' => 5.00, 'is_active' => false]);
        $orderFromDb = Order::find($orderData['id']);
        $this->assertEquals(200.00, (float) $orderFromDb->discount_amount);
        $this->assertEquals(2000.00, (float) $orderFromDb->subtotal);
    }

    /**
     * 15. Test admin authorization: unauthorized user cannot create promotions or coupons
     */
    public function test_unauthorized_user_cannot_create_promotions(): void
    {
        // Unauthenticated
        $this->postJson('/api/admin/promotions', [
            'name' => 'Unauthorized Promo',
        ])->assertStatus(401);

        // Authenticated customer (non-admin)
        Sanctum::actingAs($this->customerUser, ['customer:access']);

        $this->postJson('/api/admin/promotions', [
            'name' => 'Customer Hacking Promo',
        ])->assertStatus(403);

        $this->postJson('/api/admin/coupons', [
            'code' => 'HACK10',
        ])->assertStatus(403);
    }

    /**
     * 16. Test admin coupon validation prevents percentage > 100 and invalid dates
     */
    public function test_admin_coupon_validation_blocks_invalid_percentage_and_dates(): void
    {
        Sanctum::actingAs($this->adminUser, ['admin:access']);

        // Percentage > 100 rejected
        $this->postJson('/api/admin/coupons', [
            'code' => 'INVALID_PCT_' . Str::random(4),
            'type' => 'percentage',
            'value' => 150.00,
        ])->assertStatus(422);

        // Expiration before start date rejected
        $this->postJson('/api/admin/coupons', [
            'code' => 'INVALID_DATE_' . Str::random(4),
            'type' => 'fixed',
            'value' => 50.00,
            'starts_at' => now()->addDays(5)->toDateTimeString(),
            'expires_at' => now()->addDays(2)->toDateTimeString(),
        ])->assertStatus(422);
    }

    /**
     * 17. Test order cancellation reverses redemptions and restores usage counter
     */
    public function test_order_cancellation_restores_coupon_usage(): void
    {
        $code = 'REVERSETST' . strtoupper(Str::random(4));
        $coupon = Coupon::create([
            'code' => $code,
            'type' => 'fixed',
            'value' => 100.00,
            'usage_limit' => 5,
            'used_count' => 1,
            'is_active' => true,
        ]);

        $order = Order::create([
            'user_id' => $this->customerUser->id,
            'order_number' => 'ORD-' . date('Y') . '-' . strtoupper(Str::random(6)),
            'order_source' => 'online',
            'customer_name' => 'Cancel Customer',
            'customer_email' => $this->customerUser->email,
            'shipping_address' => ['city' => 'Dhaka'],
            'billing_address' => ['city' => 'Dhaka'],
            'subtotal' => 1000.00,
            'discount_amount' => 100.00,
            'total_amount' => 900.00,
            'payment_status' => 'pending',
            'payment_method' => 'cash_on_delivery',
            'order_status' => 'pending',
            'coupon_code' => $code,
        ]);

        PromotionRedemption::create([
            'order_id' => $order->id,
            'user_id' => $this->customerUser->id,
            'customer_email' => $this->customerUser->email,
            'code_used' => $code,
            'promotion_type' => 'discount_code',
            'discount_type' => 'fixed_amount',
            'discount_amount' => 100.00,
            'order_subtotal' => 1000.00,
            'order_total' => 900.00,
            'status' => 'completed',
        ]);

        PromotionEngine::reverseOrderRedemptions($order, 'Order cancelled by customer');

        $coupon->refresh();
        $this->assertEquals(0, $coupon->used_count); // Restored from 1 to 0
    }
}
