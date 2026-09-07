<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionClaim;
use App\Models\PromotionCode;
use App\Models\PromotionCustomerRestriction;
use App\Models\PromotionProductTarget;
use App\Models\PromotionRedemption;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PromotionEngine
{
    /**
     * Authoritatively evaluate promotions, automatic discounts, codes, and claims for a cart.
     *
     * @param array<int, array{product_id: int, variant_id?: int|null, quantity: int, unit_price?: float}> $cartItems
     * @param User|null $user
     * @param string|null $customerEmail
     * @param string|null $code
     * @param int|null $claimedCouponId ID of the PromotionClaim
     * @param float $baseShippingRate
     * @param string $paymentMethod
     * @return array
     */
    public static function evaluateCart(
        array $cartItems,
        ?User $user = null,
        ?string $customerEmail = null,
        ?string $code = null,
        ?int $claimedCouponId = null,
        float $baseShippingRate = 15.00,
        string $paymentMethod = 'cash_on_delivery'
    ): array {
        if (empty($cartItems)) {
            return [
                'valid' => true,
                'subtotal' => 0.00,
                'item_discount' => 0.00,
                'order_discount' => 0.00,
                'shipping_discount' => 0.00,
                'total_discount' => 0.00,
                'shipping_amount' => 0.00,
                'tax_amount' => 0.00,
                'grand_total' => 0.00,
                'applied_promotions' => [],
                'message' => 'Cart is empty.',
            ];
        }

        // 1. Hydrate cart items with authoritative database prices, categories, and brands
        $productIds = array_column($cartItems, 'product_id');
        $products = Product::whereIn('id', $productIds)->with(['category'])->get()->keyBy('id');

        $hydratedItems = [];
        $subtotal = 0.00;
        $totalQuantity = 0;

        foreach ($cartItems as $ci) {
            $prodId = $ci['product_id'];
            if (!$products->has($prodId)) {
                continue;
            }
            $product = $products->get($prodId);
            $qty = max(1, (int) $ci['quantity']);
            $unitPrice = (float) $product->price;

            // Check variant price modifier if variant present
            if (!empty($ci['variant_id'])) {
                $variant = \App\Models\ProductVariant::find($ci['variant_id']);
                if ($variant && $variant->product_id === $product->id) {
                    $unitPrice += (float) $variant->price_modifier;
                }
            }

            $lineTotal = round($unitPrice * $qty, 2);
            $subtotal += $lineTotal;
            $totalQuantity += $qty;

            $hydratedItems[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'category_id' => $product->category_id,
                'brand_id' => $product->brand_id,
                'variant_id' => $ci['variant_id'] ?? null,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        $appliedPromotions = [];
        $totalDiscount = 0.00;
        $itemDiscount = 0.00;
        $orderDiscount = 0.00;
        $shippingDiscount = 0.00;
        $codeErrorMessage = null;
        $claimErrorMessage = null;

        // 2. Discover and evaluate Automatic Discounts
        $automaticPromotions = Promotion::where('promotion_type', 'automatic_discount')
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->orderByDesc('priority')
            ->get();

        foreach ($automaticPromotions as $autoPromo) {
            $eval = self::evaluateSinglePromotion($autoPromo, $hydratedItems, $subtotal, $totalQuantity, $user, $customerEmail, $paymentMethod, $baseShippingRate);
            if ($eval['eligible'] && $eval['discount_amount'] > 0) {
                $appliedPromotions[] = [
                    'promotion_id' => $autoPromo->id,
                    'promotion_name' => $autoPromo->name,
                    'code' => null,
                    'type' => 'automatic_discount',
                    'discount_type' => $autoPromo->discount_type,
                    'discount_amount' => $eval['discount_amount'],
                    'breakdown' => $eval['breakdown'],
                ];

                if ($autoPromo->discount_type === 'free_shipping') {
                    $shippingDiscount = max($shippingDiscount, $eval['discount_amount']);
                } else {
                    $totalDiscount += $eval['discount_amount'];
                    $orderDiscount += $eval['discount_amount'];
                }

                // If non-stackable, break after highest priority automatic discount
                if (!$autoPromo->is_stackable) {
                    break;
                }
            }
        }

        // 3. Evaluate Claimed Coupon if specified
        $claimedClaim = null;
        if (!empty($claimedCouponId)) {
            $claimedClaim = PromotionClaim::with('promotion')->find($claimedCouponId);
            if (!$claimedClaim) {
                $claimErrorMessage = 'Claimed coupon record not found.';
            } elseif ($user && $claimedClaim->user_id !== $user->id) {
                $claimErrorMessage = 'This coupon claim belongs to a different customer account.';
            } elseif ($claimedClaim->status === 'redeemed') {
                $claimErrorMessage = 'This claimed coupon has already been redeemed.';
            } elseif ($claimedClaim->status === 'expired' || ($claimedClaim->expires_at && $claimedClaim->expires_at->isPast())) {
                $claimErrorMessage = 'This claimed coupon has expired.';
            } elseif (!$claimedClaim->promotion || $claimedClaim->promotion->status !== 'active') {
                $claimErrorMessage = 'This promotional campaign is currently inactive.';
            } else {
                $claimPromo = $claimedClaim->promotion;
                $eval = self::evaluateSinglePromotion($claimPromo, $hydratedItems, $subtotal, $totalQuantity, $user, $customerEmail, $paymentMethod, $baseShippingRate);
                if (!$eval['eligible']) {
                    $claimErrorMessage = $eval['reason'];
                } else {
                    $amount = $eval['discount_amount'];
                    if ($claimPromo->discount_type === 'free_shipping') {
                        $shippingDiscount = max($shippingDiscount, $amount);
                    } else {
                        $totalDiscount += $amount;
                        $orderDiscount += $amount;
                    }
                    $appliedPromotions[] = [
                        'promotion_id' => $claimPromo->id,
                        'promotion_name' => $claimPromo->name,
                        'claim_id' => $claimedClaim->id,
                        'code' => $claimedClaim->claimed_code,
                        'type' => 'claimable_coupon',
                        'discount_type' => $claimPromo->discount_type,
                        'discount_amount' => $amount,
                        'breakdown' => $eval['breakdown'],
                    ];
                }
            }
        }

        // 4. Evaluate Promo Code if specified
        if (!empty($code) && empty($claimedClaim)) {
            $cleanCode = strtoupper(trim($code));
            $promoCode = PromotionCode::where('code', $cleanCode)->with('promotion')->first();

            if (!$promoCode) {
                // Fallback: check direct code in promotions table
                $directPromo = Promotion::where('slug', $cleanCode)->orWhere('name', $cleanCode)->first();
                if (!$directPromo) {
                    $codeErrorMessage = "Promo code '{$cleanCode}' is invalid.";
                } else {
                    $promoCode = (object) [
                        'id' => null,
                        'code' => $cleanCode,
                        'promotion' => $directPromo,
                        'is_active' => $directPromo->status === 'active',
                        'usage_limit' => $directPromo->total_usage_limit,
                        'used_count' => $directPromo->total_used_count,
                    ];
                }
            }

            if ($promoCode && !empty($promoCode->promotion)) {
                $promo = $promoCode->promotion;

                if (!$promoCode->is_active || $promo->status !== 'active') {
                    $codeErrorMessage = "Promo code '{$cleanCode}' is currently inactive.";
                } elseif ($promo->starts_at && $promo->starts_at->isFuture()) {
                    $codeErrorMessage = "Promo code '{$cleanCode}' campaign starts on {$promo->starts_at->format('M d, Y')}.";
                } elseif ($promo->expires_at && $promo->expires_at->isPast()) {
                    $codeErrorMessage = "Promo code '{$cleanCode}' has expired on {$promo->expires_at->format('M d, Y')}.";
                } elseif ($promoCode->usage_limit !== null && $promoCode->used_count >= $promoCode->usage_limit) {
                    $codeErrorMessage = "The redemption limit for promo code '{$cleanCode}' has been reached.";
                } else {
                    // Check per-customer limit
                    $alreadyRedeemedCount = 0;
                    if ($user) {
                        $alreadyRedeemedCount = PromotionRedemption::where('promotion_id', $promo->id)
                            ->where('user_id', $user->id)
                            ->count();
                    } elseif ($customerEmail) {
                        $alreadyRedeemedCount = PromotionRedemption::where('promotion_id', $promo->id)
                            ->where('customer_email', strtolower(trim($customerEmail)))
                            ->count();
                    }

                    if ($promo->per_customer_usage_limit !== null && $alreadyRedeemedCount >= $promo->per_customer_usage_limit) {
                        $codeErrorMessage = "You have reached the maximum allowed redemptions ({$promo->per_customer_usage_limit}) for promo code '{$cleanCode}'.";
                    } else {
                        // Evaluate promotion eligibility rules
                        $eval = self::evaluateSinglePromotion($promo, $hydratedItems, $subtotal, $totalQuantity, $user, $customerEmail, $paymentMethod, $baseShippingRate);
                        if (!$eval['eligible']) {
                            $codeErrorMessage = $eval['reason'];
                        } else {
                            $amount = $eval['discount_amount'];
                            if ($promo->discount_type === 'free_shipping') {
                                $shippingDiscount = max($shippingDiscount, $amount);
                            } else {
                                $totalDiscount += $amount;
                                $orderDiscount += $amount;
                            }
                            $appliedPromotions[] = [
                                'promotion_id' => $promo->id,
                                'promotion_name' => $promo->name,
                                'code' => $cleanCode,
                                'type' => $promo->promotion_type,
                                'discount_type' => $promo->discount_type,
                                'discount_amount' => $amount,
                                'breakdown' => $eval['breakdown'],
                            ];
                        }
                    }
                }
            }
        }

        // Cap discount so it cannot exceed subtotal
        $orderDiscount = min($subtotal, round($orderDiscount, 2));
        $totalDiscount = $orderDiscount + $shippingDiscount;

        // Shipping calculation
        $effectiveShipping = max(0.00, $baseShippingRate - $shippingDiscount);
        $taxable = max(0.00, $subtotal - $orderDiscount);
        $tax = round($taxable * 0.08, 2);
        $grandTotal = round($taxable + $effectiveShipping + $tax, 2);

        $hasError = !empty($codeErrorMessage) || !empty($claimErrorMessage);
        $errorMessage = $codeErrorMessage ?: $claimErrorMessage;

        return [
            'valid' => !$hasError,
            'subtotal' => round($subtotal, 2),
            'item_discount' => round($itemDiscount, 2),
            'order_discount' => round($orderDiscount, 2),
            'shipping_discount' => round($shippingDiscount, 2),
            'total_discount' => round($totalDiscount, 2),
            'shipping_amount' => round($effectiveShipping, 2),
            'base_shipping_rate' => round($baseShippingRate, 2),
            'tax_amount' => round($tax, 2),
            'grand_total' => round($grandTotal, 2),
            'applied_promotions' => $appliedPromotions,
            'message' => $errorMessage ?: (count($appliedPromotions) > 0 ? 'Promotion applied successfully!' : null),
            'error_message' => $errorMessage,
        ];
    }

    /**
     * Evaluates a single promotion against the cart items and customer profile.
     */
    public static function evaluateSinglePromotion(
        Promotion $promo,
        array $hydratedItems,
        float $subtotal,
        int $totalQuantity,
        ?User $user,
        ?string $customerEmail,
        string $paymentMethod,
        float $shippingRate
    ): array {
        // 1. Min / Max Order Spend
        if ($promo->min_order_amount > 0 && $subtotal < (float) $promo->min_order_amount) {
            return [
                'eligible' => false,
                'discount_amount' => 0.00,
                'reason' => "Minimum order amount of \${$promo->min_order_amount} required to qualify for {$promo->name}.",
                'breakdown' => null,
            ];
        }
        if ($promo->max_order_amount !== null && $subtotal > (float) $promo->max_order_amount) {
            return [
                'eligible' => false,
                'discount_amount' => 0.00,
                'reason' => "Order exceeds the maximum spending limit of \${$promo->max_order_amount} for {$promo->name}.",
                'breakdown' => null,
            ];
        }

        // 2. Min / Max Quantity
        if ($promo->min_quantity !== null && $totalQuantity < $promo->min_quantity) {
            return [
                'eligible' => false,
                'discount_amount' => 0.00,
                'reason' => "A minimum of {$promo->min_quantity} items is required for {$promo->name}.",
                'breakdown' => null,
            ];
        }
        if ($promo->max_quantity !== null && $totalQuantity > $promo->max_quantity) {
            return [
                'eligible' => false,
                'discount_amount' => 0.00,
                'reason' => "Cart exceeds the maximum limit of {$promo->max_quantity} items for {$promo->name}.",
                'breakdown' => null,
            ];
        }

        // 3. Customer Eligibility
        if ($promo->customer_eligibility === 'first_order_only') {
            $pastOrders = 0;
            if ($user) {
                $pastOrders = Order::where('user_id', $user->id)->count();
            } elseif ($customerEmail) {
                $pastOrders = Order::where('customer_email', strtolower(trim($customerEmail)))->count();
            }
            if ($pastOrders > 0) {
                return [
                    'eligible' => false,
                    'discount_amount' => 0.00,
                    'reason' => "This promotional discount is strictly reserved for your first order.",
                    'breakdown' => null,
                ];
            }
        } elseif ($promo->customer_eligibility === 'specific_customers' || $promo->promotion_type === 'customer_reward' || $promo->promotion_type === 'next_order_discount') {
            // Check restriction table
            if (!$user && !$customerEmail) {
                return [
                    'eligible' => false,
                    'discount_amount' => 0.00,
                    'reason' => "Please sign in to redeem this customer-specific reward.",
                    'breakdown' => null,
                ];
            }
            $userId = $user?->id;
            if (!$userId && $customerEmail) {
                $foundUser = User::where('email', strtolower(trim($customerEmail)))->first();
                $userId = $foundUser?->id;
            }

            if (!$userId) {
                return [
                    'eligible' => false,
                    'discount_amount' => 0.00,
                    'reason' => "This promotion is restricted to authorized customer accounts.",
                    'breakdown' => null,
                ];
            }

            $restriction = PromotionCustomerRestriction::where('promotion_id', $promo->id)
                ->where('user_id', $userId)
                ->first();

            if (!$restriction) {
                return [
                    'eligible' => false,
                    'discount_amount' => 0.00,
                    'reason' => "Your customer account is not designated for this private reward.",
                    'breakdown' => null,
                ];
            }

            if ($restriction->is_used) {
                return [
                    'eligible' => false,
                    'discount_amount' => 0.00,
                    'reason' => "You have already redeemed your one-time customer reward.",
                    'breakdown' => null,
                ];
            }
        }

        // 4. Payment Method Restrictions
        if (!empty($promo->payment_methods) && is_array($promo->payment_methods)) {
            if (!in_array($paymentMethod, $promo->payment_methods)) {
                $methodsList = implode(', ', $promo->payment_methods);
                return [
                    'eligible' => false,
                    'discount_amount' => 0.00,
                    'reason' => "This promotion is only applicable for payment methods: {$methodsList}.",
                    'breakdown' => null,
                ];
            }
        }

        // 5. Product / Category / Brand Targeting
        $eligibleItems = $hydratedItems;
        $targets = $promo->productTargets;

        if ($promo->applies_to !== 'entire_order' && $promo->applies_to !== 'shipping') {
            if ($targets->isNotEmpty()) {
                $inclusionTargets = $targets->where('is_exclusion', false);
                $exclusionTargets = $targets->where('is_exclusion', true);

                $eligibleItems = array_filter($hydratedItems, function ($item) use ($inclusionTargets, $exclusionTargets, $promo) {
                    // Check exclusions first
                    foreach ($exclusionTargets as $ex) {
                        if ($ex->target_type === 'product' && $item['product_id'] == $ex->target_id) return false;
                        if ($ex->target_type === 'category' && $item['category_id'] == $ex->target_id) return false;
                        if ($ex->target_type === 'brand' && $item['brand_id'] == $ex->target_id) return false;
                    }

                    if ($inclusionTargets->isEmpty()) {
                        return true;
                    }

                    // Check inclusions
                    foreach ($inclusionTargets as $in) {
                        if ($in->target_type === 'product' && $item['product_id'] == $in->target_id) return true;
                        if ($in->target_type === 'category' && $item['category_id'] == $in->target_id) return true;
                        if ($in->target_type === 'brand' && $item['brand_id'] == $in->target_id) return true;
                    }

                    return false;
                });
            }
        }

        $eligibleSubtotal = array_sum(array_column($eligibleItems, 'line_total'));
        $eligibleQuantity = array_sum(array_column($eligibleItems, 'quantity'));

        if ($promo->applies_to !== 'entire_order' && $promo->applies_to !== 'shipping' && $eligibleSubtotal <= 0) {
            return [
                'eligible' => false,
                'discount_amount' => 0.00,
                'reason' => "None of the items in your cart qualify for the {$promo->name} promotion.",
                'breakdown' => null,
            ];
        }

        // 6. Calculate Discount based on discount_type
        $discountAmount = 0.00;
        $breakdown = null;

        switch ($promo->discount_type) {
            case 'free_shipping':
                $discountAmount = (float) $shippingRate;
                $breakdown = '100% Free Express Shipping';
                break;

            case 'percentage':
            case 'product_percentage_discount':
                $targetSpend = ($promo->applies_to === 'entire_order') ? $subtotal : $eligibleSubtotal;
                $calc = ($targetSpend * (float) $promo->discount_value) / 100;
                if ($promo->max_discount_amount && $calc > (float) $promo->max_discount_amount) {
                    $calc = (float) $promo->max_discount_amount;
                }
                $discountAmount = round($calc, 2);
                $breakdown = "{$promo->discount_value}% off " . ($promo->applies_to === 'entire_order' ? 'order subtotal' : 'qualifying products');
                break;

            case 'fixed_amount':
            case 'product_fixed_discount':
                $targetSpend = ($promo->applies_to === 'entire_order') ? $subtotal : $eligibleSubtotal;
                $discountAmount = round(min((float) $promo->discount_value, $targetSpend), 2);
                $breakdown = "\${$promo->discount_value} instant reduction";
                break;

            case 'buy_x_get_y':
                // Buy X Get Y Algorithm
                $buyQty = max(1, (int) $promo->bxgy_buy_quantity);
                $getQty = max(1, (int) $promo->bxgy_get_quantity);
                $rewardPercent = (float) ($promo->bxgy_reward_discount_percent ?: 100.00);
                $bundleSize = $buyQty + $getQty;

                if ($eligibleQuantity < $bundleSize) {
                    return [
                        'eligible' => false,
                        'discount_amount' => 0.00,
                        'reason' => "Requires at least {$bundleSize} items (Buy {$buyQty}, Get {$getQty}) to activate this promotion.",
                        'breakdown' => null,
                    ];
                }

                // Flatten all eligible individual units
                $units = [];
                foreach ($eligibleItems as $item) {
                    for ($i = 0; $i < $item['quantity']; $i++) {
                        $units[] = $item['unit_price'];
                    }
                }

                // Sort ascending: cheapest items get discounted (standard fair ecommerce policy)
                sort($units);

                $fullSets = (int) floor($eligibleQuantity / $bundleSize);
                if ($promo->bxgy_max_applications !== null) {
                    $fullSets = min($fullSets, $promo->bxgy_max_applications);
                }

                $freeUnitsCount = $fullSets * $getQty;
                $bxgyDiscount = 0.00;
                for ($k = 0; $k < $freeUnitsCount && $k < count($units); $k++) {
                    $unitPrice = $units[$k];
                    $bxgyDiscount += ($unitPrice * $rewardPercent) / 100;
                }

                $discountAmount = round($bxgyDiscount, 2);
                $breakdown = "Buy {$buyQty} Get {$getQty} ({$rewardPercent}% off) applied to {$fullSets} bundle(s)";
                break;

            default:
                $discountAmount = 0.00;
        }

        return [
            'eligible' => true,
            'discount_amount' => $discountAmount,
            'reason' => null,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Atomically records the redemption for an order inside an ongoing database transaction.
     */
    public static function recordOrderRedemption(
        Order $order,
        array $evalResult,
        ?User $user,
        string $customerEmail
    ): void {
        foreach ($evalResult['applied_promotions'] as $applied) {
            $promoId = $applied['promotion_id'] ?? null;
            if (!$promoId) {
                continue;
            }

            // Lock promotion row for concurrency safety
            $promo = Promotion::where('id', $promoId)->lockForUpdate()->first();
            if (!$promo) {
                continue;
            }

            $promo->increment('total_used_count');

            $codeId = null;
            if (!empty($applied['code'])) {
                $codeObj = PromotionCode::where('code', $applied['code'])->lockForUpdate()->first();
                if ($codeObj) {
                    $codeObj->increment('used_count');
                    $codeId = $codeObj->id;
                }
            }

            $claimId = $applied['claim_id'] ?? null;
            if ($claimId) {
                $claim = PromotionClaim::where('id', $claimId)->lockForUpdate()->first();
                if ($claim) {
                    $claim->update([
                        'status' => 'redeemed',
                        'redeemed_at' => now(),
                        'order_id' => $order->id,
                    ]);
                }
            }

            // If customer restricted reward, mark used
            if ($user) {
                PromotionCustomerRestriction::where('promotion_id', $promo->id)
                    ->where('user_id', $user->id)
                    ->update([
                        'is_used' => true,
                        'used_at' => now(),
                    ]);
            }

            // Record immutable audit redemption
            PromotionRedemption::create([
                'promotion_id' => $promo->id,
                'promotion_code_id' => $codeId,
                'promotion_claim_id' => $claimId,
                'order_id' => $order->id,
                'user_id' => $user?->id,
                'customer_email' => strtolower(trim($customerEmail)),
                'code_used' => $applied['code'] ?? null,
                'promotion_type' => $applied['type'] ?? $promo->promotion_type,
                'discount_type' => $applied['discount_type'] ?? $promo->discount_type,
                'discount_amount' => (float) ($applied['discount_amount'] ?? 0.00),
                'order_subtotal' => (float) $order->subtotal,
                'order_total' => (float) $order->total_amount,
                'created_at' => now(),
            ]);
        }
    }
}
