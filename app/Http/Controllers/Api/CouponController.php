<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Promotion;
use App\Models\PromotionCode;
use App\Services\PromotionEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function validateCoupon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'subtotal' => 'required|numeric|min:0',
            'items' => 'nullable|array',
        ]);

        $code = strtoupper(trim($validated['code']));
        $subtotal = (float) $validated['subtotal'];
        $user = $request->user('sanctum');

        // Check in unified Promotion engine first
        $promoCode = PromotionCode::where('code', $code)->with('promotion')->first();
        if ($promoCode && $promoCode->promotion) {
            $promo = $promoCode->promotion;
            // Build dummy cart items if not provided
            $cartItems = $validated['items'] ?? [
                ['product_id' => 1, 'quantity' => 1, 'unit_price' => $subtotal]
            ];

            $eval = PromotionEngine::evaluateCart($cartItems, $user, $user?->email, $code, null);
            if (!$eval['valid'] || empty($eval['applied_promotions'])) {
                return response()->json([
                    'valid' => false,
                    'message' => $eval['message'] ?: 'Coupon is not eligible for this order.',
                ], 422);
            }

            $discountAmount = $eval['order_discount'] + $eval['shipping_discount'];
            return response()->json([
                'valid' => true,
                'code' => $code,
                'type' => $promo->discount_type,
                'value' => (float) $promo->discount_value,
                'discount_amount' => $discountAmount,
                'promotion_id' => $promo->id,
                'promotion_name' => $promo->name,
                'message' => 'Promo code applied successfully!',
            ]);
        }

        // Fallback to legacy Coupon table
        $coupon = Coupon::where('code', $code)->first();

        if (!$coupon) {
            return response()->json([
                'valid' => false,
                'message' => 'Invalid promo code.',
            ], 404);
        }

        if (!$coupon->isValid($subtotal)) {
            $msg = 'Coupon is not eligible for this order total.';
            if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
                $msg = "This coupon campaign begins on {$coupon->starts_at->format('M d, Y g:i A')} and is not yet active.";
            } elseif ($coupon->expires_at && $coupon->expires_at->isPast()) {
                $msg = "This coupon code '{$coupon->code}' has expired on {$coupon->expires_at->format('M d, Y')} and is no longer valid.";
            } elseif (!$coupon->is_active) {
                $msg = "This coupon code '{$coupon->code}' is currently inactive.";
            } elseif ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
                $msg = "The redemption limit for coupon '{$coupon->code}' has been reached.";
            } elseif ($subtotal < $coupon->min_order_amount) {
                $msg = "Minimum order amount of \${$coupon->min_order_amount} required to use this coupon.";
            }
            return response()->json([
                'valid' => false,
                'message' => $msg,
            ], 422);
        }

        $discount = $coupon->calculateDiscount($subtotal);

        return response()->json([
            'valid' => true,
            'code' => $coupon->code,
            'type' => $coupon->type,
            'value' => (float) $coupon->value,
            'discount_amount' => $discount,
            'message' => 'Promo code applied successfully!',
        ]);
    }
}
