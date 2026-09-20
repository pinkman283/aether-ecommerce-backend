<?php

namespace Tests\Feature\Promotion;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionClaim;
use App\Models\PromotionCode;
use App\Models\PromotionCustomerRestriction;
use App\Models\PromotionRedemption;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\PromotionEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class PromotionRemediationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed standard accounts for accounting
        \Artisan::call('db:seed', ['--class' => 'ChartOfAccountsSeeder']);
    }

    public function test_claimable_coupon_redemption_marks_claim_redeemed_and_creates_audit_record(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'status' => 'active',
        ]);

        $product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product-' . Str::random(6),
            'sku' => 'TEST-001',
            'price' => 500.00,
            'cost_price' => 300.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $promotion = Promotion::create([
            'name' => 'Save 100 Voucher',
            'slug' => 'save-100-voucher',
            'promotion_type' => 'claimable_coupon',
            'discount_type' => 'fixed_amount',
            'discount_value' => 100.00,
            'applies_to' => 'entire_order',
            'min_order_amount' => 200.00,
            'status' => 'active',
            'claim_validity_days' => 7,
            'total_usage_limit' => 50,
            'total_used_count' => 0,
        ]);

        $claim = PromotionClaim::create([
            'promotion_id' => $promotion->id,
            'user_id' => $user->id,
            'claimed_code' => 'VOUCHER100',
            'status' => 'claimed',
            'claimed_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $payload = [
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'customer_phone' => '01711111111',
            'shipping_address' => [
                'full_name' => $user->name,
                'address_line1' => '123 Test Street',
                'city' => 'Dhaka',
                'country' => 'Bangladesh',
                'postal_code' => '1000',
            ],
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'inside_dhaka',
            'coupon_code' => 'VOUCHER100',
            'claimed_coupon_id' => $claim->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/orders', $payload);
        $response->assertStatus(201);

        $claim->refresh();
        $this->assertEquals('redeemed', $claim->status);
        $this->assertNotNull($claim->redeemed_at);
        $this->assertNotNull($claim->order_id);

        $redemption = PromotionRedemption::where('order_id', $claim->order_id)->first();
        $this->assertNotNull($redemption);
        $this->assertEquals('completed', $redemption->status);
        $this->assertEquals(100.00, (float) $redemption->discount_amount);
        $this->assertEquals($claim->id, $redemption->promotion_claim_id);

        $promotion->refresh();
        $this->assertEquals(1, $promotion->total_used_count);
    }

    public function test_item_level_discount_allocation_largest_remainder_sums_exactly(): void
    {
        $items = [
            ['product_id' => 1, 'line_total' => 333.33, 'quantity' => 1],
            ['product_id' => 2, 'line_total' => 333.33, 'quantity' => 1],
            ['product_id' => 3, 'line_total' => 333.34, 'quantity' => 1],
        ];

        $totalDiscount = 100.00;
        $allocated = PromotionEngine::allocateDiscountToItems($items, $totalDiscount);

        $allocatedSum = round(array_sum(array_column($allocated, 'discount_amount')), 2);
        $this->assertEquals(100.00, $allocatedSum);

        $netSum = round(array_sum(array_column($allocated, 'net_total')), 2);
        $this->assertEquals(900.00, $netSum);
    }

    public function test_guest_cannot_redeem_customer_restricted_reward(): void
    {
        $user = User::factory()->create(['email' => 'vip@example.com']);

        $promotion = Promotion::create([
            'name' => 'VIP Secret Discount',
            'slug' => 'vip-secret',
            'promotion_type' => 'customer_reward',
            'discount_type' => 'fixed_amount',
            'discount_value' => 50.00,
            'customer_eligibility' => 'specific_customers',
            'status' => 'active',
        ]);

        PromotionCustomerRestriction::create([
            'promotion_id' => $promotion->id,
            'user_id' => $user->id,
            'is_used' => false,
        ]);

        PromotionCode::create([
            'promotion_id' => $promotion->id,
            'code' => 'VIP50',
            'is_active' => true,
        ]);

        // Evaluate without authenticated session using VIP email
        $eval = PromotionEngine::evaluateCart(
            cartItems: [['product_id' => 1, 'quantity' => 1]],
            user: null,
            customerEmail: 'vip@example.com',
            code: 'VIP50'
        );

        $this->assertFalse($eval['valid']);
        $this->assertStringContainsString('sign in', strtolower($eval['error_message']));
    }

    public function test_first_order_only_checks_customer_phone(): void
    {
        $existingOrder = Order::create([
            'order_number' => 'ORD-PRIOR-001',
            'customer_name' => 'Original Customer',
            'customer_email' => 'first@example.com',
            'customer_phone' => '01799999999',
            'subtotal' => 500.00,
            'shipping_amount' => 60.00,
            'total_amount' => 560.00,
            'payment_status' => 'paid',
            'order_status' => 'delivered',
            'shipping_address' => ['address' => 'Dhaka'],
        ]);

        $promotion = Promotion::create([
            'name' => 'New Customers Only',
            'slug' => 'new-customers-only',
            'promotion_type' => 'discount_code',
            'discount_type' => 'percentage',
            'discount_value' => 20.00,
            'customer_eligibility' => 'first_order_only',
            'status' => 'active',
        ]);

        PromotionCode::create([
            'promotion_id' => $promotion->id,
            'code' => 'FIRSTPHONE20',
            'is_active' => true,
        ]);

        // A guest attempts to use the code with a DIFFERENT email but the SAME phone
        $eval = PromotionEngine::evaluateCart(
            cartItems: [['product_id' => 1, 'quantity' => 1]],
            user: null,
            customerEmail: 'different_email@example.com',
            code: 'FIRSTPHONE20',
            customerPhone: '01799999999'
        );

        $this->assertFalse($eval['valid']);
        $this->assertStringContainsString('first order', strtolower($eval['error_message']));
    }

    public function test_order_cancellation_reverses_redemptions_and_restores_claim(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $promotion = Promotion::create([
            'name' => 'Test Reversal Voucher',
            'slug' => 'test-reversal',
            'promotion_type' => 'claimable_coupon',
            'discount_type' => 'fixed_amount',
            'discount_value' => 50.00,
            'status' => 'active',
            'total_used_count' => 1,
        ]);

        $claim = PromotionClaim::create([
            'promotion_id' => $promotion->id,
            'user_id' => $user->id,
            'claimed_code' => 'REVERSE50',
            'status' => 'redeemed',
            'claimed_at' => now()->subDay(),
            'redeemed_at' => now(),
            'expires_at' => now()->addDays(5),
            'order_id' => 9999,
        ]);

        $order = Order::create([
            'id' => 9999,
            'order_number' => 'ORD-REVERSE-9999',
            'user_id' => $user->id,
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'subtotal' => 200.00,
            'shipping_amount' => 60.00,
            'discount_amount' => 50.00,
            'total_amount' => 210.00,
            'order_status' => 'pending',
            'payment_status' => 'pending',
            'shipping_address' => ['address' => 'Dhaka'],
        ]);

        $redemption = PromotionRedemption::create([
            'promotion_id' => $promotion->id,
            'promotion_claim_id' => $claim->id,
            'promotion_type' => 'claimable_coupon',
            'discount_type' => 'fixed_amount',
            'order_id' => $order->id,
            'user_id' => $user->id,
            'customer_email' => $user->email,
            'discount_amount' => 50.00,
            'order_subtotal' => 200.00,
            'order_total' => 210.00,
            'status' => 'completed',
            'created_at' => now(),
        ]);

        // Cancel order via PromotionEngine reverse hook
        PromotionEngine::reverseOrderRedemptions($order, 'Order cancelled by customer');

        $redemption->refresh();
        $this->assertEquals('reversed', $redemption->status);
        $this->assertNotNull($redemption->reversed_at);

        $claim->refresh();
        $this->assertEquals('claimed', $claim->status);
        $this->assertNull($claim->order_id);
        $this->assertNull($claim->redeemed_at);

        $promotion->refresh();
        $this->assertEquals(0, $promotion->total_used_count);
    }

    public function test_free_shipping_accounting_debits_4090_and_credits_4030_balanced(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-FREESHIP-001',
            'customer_name' => 'Ship Customer',
            'customer_email' => 'ship@example.com',
            'subtotal' => 1000.00,
            'shipping_amount' => 0.00, // Effective shipping is 0
            'discount_amount' => 0.00, // Order discount is 0
            'tax_amount' => 0.00,
            'total_amount' => 1000.00,
            'order_status' => 'pending',
            'payment_status' => 'pending',
            'shipping_address' => ['address' => 'Dhaka'],
            'promotion_discount_details' => [
                [
                    'discount_type' => 'free_shipping',
                    'discount_amount' => 120.00, // Gross shipping was 120
                ],
            ],
        ]);

        $entry = AccountingService::postOrderSale($order);
        $this->assertNotNull($entry);

        $lines = $entry->lines()->with('account')->get();

        // Check Debit 4090 includes 120
        $debit4090 = $lines->first(fn ($l) => $l->account?->account_code === '4090');
        $this->assertNotNull($debit4090);
        $this->assertEquals(120.00, (float) $debit4090->debit);

        // Check Credit 4030 is 120 (Gross shipping income)
        $credit4030 = $lines->first(fn ($l) => $l->account?->account_code === '4030');
        $this->assertNotNull($credit4030);
        $this->assertEquals(120.00, (float) $credit4030->credit);

        // Verify total debits equal total credits
        $totalDebits = round($lines->sum('debit'), 2);
        $totalCredits = round($lines->sum('credit'), 2);
        $this->assertEquals($totalDebits, $totalCredits);
    }
}
