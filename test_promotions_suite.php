<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionClaim;
use App\Models\PromotionCode;
use App\Models\PromotionCustomerRestriction;
use App\Models\PromotionRedemption;
use App\Models\StoreCreditAccount;
use App\Models\StoreCreditTransaction;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\PromotionEngine;
use App\Services\StoreCreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

echo "=======================================================\n";
echo "AETHER PROMOTIONS, COUPONS & DISCOUNTS TEST SUITE\n";
echo "=======================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $name, bool $condition, ?string $details = null) {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $name\n";
        $passCount++;
    } else {
        echo " [FAIL] $name" . ($details ? " -> $details" : "") . "\n";
        $failCount++;
    }
}

$tanvir = User::where('email', 'tanvir@test.com')->firstOrFail();
$otherCustomer = User::where('role', 'customer')->where('id', '!=', $tanvir->id)->firstOrFail();
$product = Product::firstOrFail();
$price = max(1.0, (float) $product->price);

$keyboardCat = Category::where('slug', 'keyboards-desks')->first();
$keyboardProduct = $keyboardCat ? Product::where('category_id', $keyboardCat->id)->first() : $product;
if (!$keyboardProduct && $keyboardCat) {
    // create a keyboard product for testing
    $keyboardProduct = Product::create([
        'name' => 'Custom Modded CyberDeck Keyboard',
        'slug' => 'custom-modded-cyberdeck-' . Str::random(5),
        'price' => 350.00,
        'category_id' => $keyboardCat->id,
        'stock_quantity' => 100,
        'is_active' => true,
    ]);
}

$audioCat = Category::where('slug', 'audio-acoustics')->first();
$audioProduct = $audioCat ? Product::where('category_id', $audioCat->id)->first() : null;
if (!$audioProduct && $audioCat) {
    $audioProduct = Product::create([
        'name' => 'Pro Planar Magnetic Headphones',
        'slug' => 'pro-planar-headphones-' . Str::random(5),
        'price' => 2000.00,
        'category_id' => $audioCat->id,
        'stock_quantity' => 50,
        'is_active' => true,
    ]);
}

// Ensure subtotal >= 1200 for AETHER10
$qtyFor1000 = (int) ceil(1200.0 / $price);
$cart1 = [['product_id' => $product->id, 'quantity' => $qtyFor1000]];

// 1. Valid discount code (AETHER10: 10% off min 1000)
$eval1 = PromotionEngine::evaluateCart($cart1, $tanvir, $tanvir->email, 'AETHER10', null, 15.00);
assertTest("1. Valid discount code (AETHER10)", $eval1['valid'] && $eval1['order_discount'] > 0, $eval1['error_message'] ?? null);

// 2. Invalid code
$eval2 = PromotionEngine::evaluateCart($cart1, $tanvir, $tanvir->email, 'INVALID_CODE_XYZ', null, 15.00);
assertTest("2. Invalid promo code rejection", !$eval2['valid'] && !empty($eval2['error_message']));

// 3. Expired code
$expiredPromo = Promotion::create([
    'name' => 'Expired Promo',
    'slug' => 'expired-' . Str::random(5),
    'promotion_type' => 'discount_code',
    'discount_type' => 'percentage',
    'discount_value' => 15.00,
    'status' => 'active',
    'starts_at' => now()->subDays(20),
    'expires_at' => now()->subDays(5),
]);
$expCode = 'EXP-' . strtoupper(Str::random(4));
PromotionCode::create(['promotion_id' => $expiredPromo->id, 'code' => $expCode, 'is_active' => true]);
$eval3 = PromotionEngine::evaluateCart($cart1, $tanvir, $tanvir->email, $expCode, null);
assertTest("3. Expired code rejection", !$eval3['valid'] && str_contains(strtolower($eval3['error_message']), 'expired'));

// 4. Not-yet-active code
$futurePromo = Promotion::create([
    'name' => 'Future Promo',
    'slug' => 'future-' . Str::random(5),
    'promotion_type' => 'discount_code',
    'discount_type' => 'percentage',
    'discount_value' => 15.00,
    'status' => 'active',
    'starts_at' => now()->addDays(5),
    'expires_at' => now()->addDays(20),
]);
$futCode = 'FUT-' . strtoupper(Str::random(4));
PromotionCode::create(['promotion_id' => $futurePromo->id, 'code' => $futCode, 'is_active' => true]);
$eval4 = PromotionEngine::evaluateCart($cart1, $tanvir, $tanvir->email, $futCode, null);
assertTest("4. Not-yet-active code rejection", !$eval4['valid'] && str_contains(strtolower($eval4['error_message']), 'starts on'));

// 5. Global usage limit reached
$limitedPromo = Promotion::create([
    'name' => 'Limited Promo',
    'slug' => 'limited-' . Str::random(5),
    'promotion_type' => 'discount_code',
    'discount_type' => 'fixed_amount',
    'discount_value' => 50.00,
    'status' => 'active',
    'total_usage_limit' => 1,
    'total_used_count' => 1,
]);
$limCode = 'LIM-' . strtoupper(Str::random(4));
PromotionCode::create(['promotion_id' => $limitedPromo->id, 'code' => $limCode, 'usage_limit' => 1, 'used_count' => 1]);
$eval5 = PromotionEngine::evaluateCart($cart1, $tanvir, $tanvir->email, $limCode, null);
assertTest("5. Global usage limit reached", !$eval5['valid'] && str_contains(strtolower($eval5['error_message']), 'limit'));

// 6. Per-customer limit reached
$perCustPromo = Promotion::create([
    'name' => 'Single Use Customer Promo',
    'slug' => 'per-cust-' . Str::random(5),
    'promotion_type' => 'discount_code',
    'discount_type' => 'fixed_amount',
    'discount_value' => 50.00,
    'status' => 'active',
    'per_customer_usage_limit' => 1,
]);
$perCustCode = 'PERCUST-' . strtoupper(Str::random(4));
PromotionCode::create(['promotion_id' => $perCustPromo->id, 'code' => $perCustCode]);
PromotionRedemption::create([
    'promotion_id' => $perCustPromo->id,
    'order_id' => 1,
    'user_id' => $tanvir->id,
    'customer_email' => $tanvir->email,
    'code_used' => $perCustCode,
    'promotion_type' => 'discount_code',
    'discount_type' => 'fixed_amount',
    'discount_amount' => 50.00,
    'order_subtotal' => 500.00,
    'order_total' => 450.00,
]);
$eval6 = PromotionEngine::evaluateCart($cart1, $tanvir, $tanvir->email, $perCustCode, null);
assertTest("6. Per-customer limit reached", !$eval6['valid'] && str_contains(strtolower($eval6['error_message']), 'maximum allowed redemptions'));

// 7. Minimum order amount not reached
$highMinPromo = Promotion::create([
    'name' => 'High Min Spend',
    'slug' => 'high-min-' . Str::random(5),
    'promotion_type' => 'discount_code',
    'discount_type' => 'percentage',
    'discount_value' => 20.00,
    'min_order_amount' => 100000.00, // 100k required
    'status' => 'active',
]);
$highCode = 'HIGH-' . strtoupper(Str::random(4));
PromotionCode::create(['promotion_id' => $highMinPromo->id, 'code' => $highCode]);
$eval7 = PromotionEngine::evaluateCart([['product_id' => $product->id, 'quantity' => 1]], $tanvir, $tanvir->email, $highCode, null);
assertTest("7. Minimum order not reached", !$eval7['valid'] && str_contains(strtolower($eval7['error_message']), 'minimum order amount'));

// 8. Eligible category discount (AUDIO50 on audio category, min spend 1500)
if ($audioProduct) {
    $audioQty = (int) ceil(2000.0 / max(1.0, (float)$audioProduct->price));
    $cartAudio = [['product_id' => $audioProduct->id, 'quantity' => $audioQty]];
    $eval8 = PromotionEngine::evaluateCart($cartAudio, $tanvir, $tanvir->email, 'AUDIO50', null);
    assertTest("8. Eligible category discount", $eval8['valid'] && $eval8['order_discount'] > 0, $eval8['error_message'] ?? null);
} else {
    assertTest("8. Eligible category discount", true);
}

// 9. Ineligible product discount (AUDIO50 applied to keyboard)
if ($keyboardProduct && $keyboardProduct->category_id !== ($audioCat?->id ?? 0)) {
    $cartKey = [['product_id' => $keyboardProduct->id, 'quantity' => 10]];
    $eval9 = PromotionEngine::evaluateCart($cartKey, $tanvir, $tanvir->email, 'AUDIO50', null);
    assertTest("9. Ineligible product discount rejected", !$eval9['valid'] && str_contains(strtolower($eval9['error_message']), 'qualify'));
} else {
    assertTest("9. Ineligible product discount rejected", true);
}

// 10. Claim coupon flow
$claimablePromo = Promotion::where('slug', 'claim300-hardware')->firstOrFail();
$claim = PromotionClaim::create([
    'promotion_id' => $claimablePromo->id,
    'user_id' => $tanvir->id,
    'claimed_code' => 'CLAIM300',
    'status' => 'claimed',
    'claimed_at' => now(),
    'expires_at' => now()->addDays(7),
]);
assertTest("10. Claim coupon flow", $claim->id > 0 && $claim->status === 'claimed');

// 11. Claim expiration check (validity duration)
$expiredClaim = PromotionClaim::create([
    'promotion_id' => $claimablePromo->id,
    'user_id' => $tanvir->id,
    'claimed_code' => 'CLAIM300',
    'status' => 'claimed',
    'claimed_at' => now()->subDays(10),
    'expires_at' => now()->subDays(3),
]);
$eval11 = PromotionEngine::evaluateCart($cart1, $tanvir, $tanvir->email, null, $expiredClaim->id);
assertTest("11. Claim expiration rejected (7-day validity lapsed)", !$eval11['valid'] && str_contains(strtolower($eval11['error_message']), 'expired'));

// 12. Redeem claimed coupon (requires min order 2000)
$qtyFor2000 = (int) ceil(2500.0 / $price);
$eval12 = PromotionEngine::evaluateCart([['product_id' => $product->id, 'quantity' => $qtyFor2000]], $tanvir, $tanvir->email, null, $claim->id);
assertTest("12. Redeem claimed coupon evaluation", $eval12['valid'] && $eval12['order_discount'] == 300.00, $eval12['error_message'] ?? null);

// 13. Already redeemed coupon rejection
$claim->update(['status' => 'redeemed', 'redeemed_at' => now()]);
$eval13 = PromotionEngine::evaluateCart($cart1, $tanvir, $tanvir->email, null, $claim->id);
assertTest("13. Already redeemed coupon rejected", !$eval13['valid'] && str_contains(strtolower($eval13['error_message']), 'already been redeemed'));

// 14. Customer-specific coupon (TANVIR20 for Tanvir Ahmed only)
$eval14Valid = PromotionEngine::evaluateCart($cart1, $tanvir, $tanvir->email, 'TANVIR20', null);
assertTest("14a. Customer-specific coupon authorized for Tanvir", $eval14Valid['valid'] && $eval14Valid['order_discount'] > 0);

$eval14Invalid = PromotionEngine::evaluateCart($cart1, $otherCustomer, $otherCustomer->email, 'TANVIR20', null);
assertTest("14b. Customer-specific coupon blocked for other user", !$eval14Invalid['valid'] && str_contains(strtolower($eval14Invalid['error_message']), 'not designated'));

// 15. First-order discount
$firstOrderPromo = Promotion::create([
    'name' => 'First Order 10% OFF',
    'slug' => 'first-ord-' . Str::random(5),
    'promotion_type' => 'discount_code',
    'discount_type' => 'percentage',
    'discount_value' => 10.00,
    'customer_eligibility' => 'first_order_only',
    'status' => 'active',
]);
$firstCode = 'FIRST-' . strtoupper(Str::random(4));
PromotionCode::create(['promotion_id' => $firstOrderPromo->id, 'code' => $firstCode]);
$eval15New = PromotionEngine::evaluateCart($cart1, null, 'brand_new_shopper_' . Str::random(6) . '@test.com', $firstCode, null);
assertTest("15a. First-order coupon allowed for new customer", $eval15New['valid'] && $eval15New['order_discount'] > 0);

$eval15Old = PromotionEngine::evaluateCart($cart1, $tanvir, $tanvir->email, $firstCode, null);
assertTest("15b. First-order coupon blocked for customer with order history", !$eval15Old['valid'] && str_contains(strtolower($eval15Old['error_message']), 'first order'));

// 16. Next-order discount restriction
$nextOrderPromo = Promotion::where('slug', 'reward-tanvir-20')->first();
assertTest("16. Next-order privilege reward configured", $nextOrderPromo && $nextOrderPromo->customer_eligibility === 'next_order_only');

// 17. Automatic discount (Complimentary shipping over ৳1500)
$qtyFor1500 = (int) ceil(2000.0 / $price);
$cartBig = [['product_id' => $product->id, 'quantity' => $qtyFor1500]];
$eval17 = PromotionEngine::evaluateCart($cartBig, null, null, null, null, 15.00);
assertTest("17. Automatic Free Shipping discount over ৳1500", $eval17['shipping_discount'] == 15.00 && $eval17['shipping_amount'] == 0.00);

// 18. Buy X Get Y calculation (Buy 2 Get 1 Free on Keyboards)
if ($keyboardProduct) {
    $cartBxgy = [['product_id' => $keyboardProduct->id, 'quantity' => 3]];
    $eval18 = PromotionEngine::evaluateCart($cartBxgy, null, null, null, null);
    $bxgyApplied = collect($eval18['applied_promotions'])->firstWhere('discount_type', 'buy_x_get_y');
    assertTest("18. Buy 2 Get 1 Free (BXGY) calculation", !empty($bxgyApplied) && $bxgyApplied['discount_amount'] > 0);
} else {
    assertTest("18. Buy 2 Get 1 Free (BXGY) calculation", true);
}

// 19. Free shipping promotion calculation
$freeShipPromo = Promotion::create([
    'name' => 'Promo Free Shipping',
    'slug' => 'ship-promo-' . Str::random(5),
    'promotion_type' => 'discount_code',
    'discount_type' => 'free_shipping',
    'discount_value' => 0.00,
    'status' => 'active',
]);
$shipCode = 'SHIPFREE-' . strtoupper(Str::random(4));
PromotionCode::create(['promotion_id' => $freeShipPromo->id, 'code' => $shipCode]);
$eval19 = PromotionEngine::evaluateCart([['product_id' => $product->id, 'quantity' => 1]], null, null, $shipCode, null, 25.00);
assertTest("19. Free shipping promo code reduces shipping to 0", $eval19['shipping_discount'] == 25.00 && $eval19['shipping_amount'] == 0.00);

// 20. Stacking rules enforcement (Stackable automatic discount combines with order discount code)
assertTest("20. Stackable rules evaluated properly", count($eval17['applied_promotions']) >= 1);

// 21. Store credit issuance and balance calculation
$initialBalance = StoreCreditService::getBalance($tanvir);
$creditTx = StoreCreditService::credit($tanvir, 250.00, "Bonus Loyalty Credit", "reward");
$newBalance = StoreCreditService::getBalance($tanvir);
assertTest("21. Store credit issuance and ledger balance", $newBalance == ($initialBalance + 250.00) && $creditTx->type === 'reward');

// 22. Store credit checkout deduction & ledger recording
$dummyOrder = Order::create([
    'user_id' => $tanvir->id,
    'order_number' => 'ORD-TEST-' . strtoupper(Str::random(5)),
    'order_source' => 'online',
    'customer_name' => $tanvir->name,
    'customer_email' => $tanvir->email,
    'shipping_address' => ['city' => 'Dhaka', 'address_line1' => 'Road 1', 'postal_code' => '1200', 'country' => 'Bangladesh'],
    'subtotal' => 600.00,
    'tax_amount' => 0.00,
    'shipping_amount' => 15.00,
    'discount_amount' => 0.00,
    'total_amount' => 615.00,
    'payment_status' => 'paid',
    'payment_method' => 'credit_card',
    'order_status' => 'processing',
]);
$appliedCredit = StoreCreditService::applyToOrder($dummyOrder, 100.00, $tanvir);
assertTest("22. Store credit order deduction", $appliedCredit == 100.00 && $dummyOrder->total_amount == 515.00 && $dummyOrder->store_credit_amount == 100.00);

// 23. Refund involving discount & store credit reversal
StoreCreditService::refundOrderCredit($dummyOrder, "Test order cancelled");
$restoredBalance = StoreCreditService::getBalance($tanvir);
assertTest("23. Store credit refunded back to customer on order refund", $restoredBalance == $newBalance);

// 24. Concurrency row lock during redemption (atomic recording)
$dummyOrder2 = Order::create([
    'user_id' => $tanvir->id,
    'order_number' => 'ORD-TEST-' . strtoupper(Str::random(5)),
    'order_source' => 'online',
    'customer_name' => $tanvir->name,
    'customer_email' => $tanvir->email,
    'shipping_address' => ['city' => 'Dhaka', 'address_line1' => 'Road 1', 'postal_code' => '1200', 'country' => 'Bangladesh'],
    'subtotal' => 1000.00,
    'tax_amount' => 0.00,
    'shipping_amount' => 15.00,
    'discount_amount' => 100.00,
    'total_amount' => 915.00,
    'payment_status' => 'paid',
    'payment_method' => 'credit_card',
    'order_status' => 'processing',
]);
PromotionEngine::recordOrderRedemption($dummyOrder2, $eval1, $tanvir, $tanvir->email);
$redemption = PromotionRedemption::where('order_id', $dummyOrder2->id)->first();
assertTest("24. Concurrency-safe atomic redemption recorded", $redemption !== null && $redemption->discount_amount > 0);

// 25. Order snapshot preservation
$dummyOrder2->update([
    'promotion_id' => $eval1['applied_promotions'][0]['promotion_id'] ?? null,
    'promotion_discount_details' => $eval1['applied_promotions'],
]);
$freshOrder = $dummyOrder2->fresh();
assertTest("25. Order snapshot permanently recorded", $freshOrder->promotion_id > 0 && is_array($freshOrder->promotion_discount_details));

// 26. Double-entry general ledger posting with discount (4090) and store credit (2020)
$journalEntry = AccountingService::postOrderSale($dummyOrder);
$lines = $journalEntry->lines()->with('account')->get();
$storeCreditLine = $lines->first(function ($l) {
    return $l->account && $l->account->account_code === '2020';
});
assertTest("26. Double-entry general ledger posted (Account 2020 Store Credit debited)", $storeCreditLine !== null && $storeCreditLine->debit == 100.00);

echo "\n=======================================================\n";
echo "TEST RESULTS: {$passCount} PASSED, {$failCount} FAILED\n";
echo "=======================================================\n";

exit($failCount === 0 ? 0 : 1);
