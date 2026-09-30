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

        // 1. If cart items are provided, execute full PromotionEngine evaluation
        if (!empty($validated['items']) && is_array($validated['items'])) {
            $eval = PromotionEngine::evaluateCart($validated['items'], $user, $user?->email, $code, null);
            if (!$eval['valid'] || empty($eval['applied_promotions'])) {
                return response()->json([
                    'valid' => false,
                    'code' => $code,
                    'error_type' => 'ineligible_cart',
                    'message' => $eval['message'] ?: 'Coupon is not eligible for this order.',
                ], 422);
            }

            $applied = $eval['applied_promotions'][0];
            $discountAmount = min($subtotal, (float) ($eval['order_discount'] + $eval['shipping_discount']));
            return response()->json([
                'valid' => true,
                'code' => $code,
                'type' => $applied['discount_type'],
                'value' => (float) ($applied['discount_amount'] ?? 0),
                'discount_amount' => round($discountAmount, 2),
                'promotion_id' => $applied['promotion_id'] ?? null,
                'promotion_name' => $applied['promotion_name'] ?? ('Coupon ' . $code),
                'message' => 'Promo code applied successfully!',
            ]);
        }

        // 2. Direct validation without cart items: Check PromotionCode first
        $promoCode = PromotionCode::where('code', $code)->with('promotion')->first();
        if ($promoCode && $promoCode->promotion) {
            $promo = $promoCode->promotion;

            if (!$promoCode->is_active || $promo->status !== 'active') {
                return response()->json([
                    'valid' => false,
                    'code' => $code,
                    'error_type' => 'inactive_coupon',
                    'message' => "Promo code '{$code}' is currently inactive.",
                ], 422);
            }
            if ($promo->starts_at && $promo->starts_at->isFuture()) {
                return response()->json([
                    'valid' => false,
                    'code' => $code,
                    'error_type' => 'coupon_not_started',
                    'message' => "Promo code '{$code}' campaign starts on {$promo->starts_at->format('M d, Y')}.",
                ], 422);
            }
            if ($promo->expires_at && $promo->expires_at->isPast()) {
                return response()->json([
                    'valid' => false,
                    'code' => $code,
                    'error_type' => 'expired_coupon',
                    'message' => "Promo code '{$code}' has expired on {$promo->expires_at->format('M d, Y')}.",
                ], 422);
            }
            if ($promo->total_usage_limit !== null && $promo->total_used_count >= $promo->total_usage_limit) {
                return response()->json([
                    'valid' => false,
                    'code' => $code,
                    'error_type' => 'usage_limit_reached',
                    'message' => "The redemption limit for promotion '{$promo->name}' has been reached.",
                ], 422);
            }
            if ($promoCode->usage_limit !== null && $promoCode->used_count >= $promoCode->usage_limit) {
                return response()->json([
                    'valid' => false,
                    'code' => $code,
                    'error_type' => 'usage_limit_reached',
                    'message' => "The redemption limit for promo code '{$code}' has been reached.",
                ], 422);
            }
            if ($promo->min_order_amount > 0 && $subtotal < (float) $promo->min_order_amount) {
                return response()->json([
                    'valid' => false,
                    'code' => $code,
                    'error_type' => 'minimum_order_not_met',
                    'message' => "Minimum order amount of \${$promo->min_order_amount} required to qualify for {$promo->name}.",
                ], 422);
            }

            // Calculate discount for entire_order promotion
            $discountAmount = 0.00;
            if ($promo->discount_type === 'percentage' || $promo->discount_type === 'product_percentage_discount') {
                $discountAmount = ($subtotal * (float) $promo->discount_value) / 100;
                if ($promo->max_discount_amount && $discountAmount > (float) $promo->max_discount_amount) {
                    $discountAmount = (float) $promo->max_discount_amount;
                }
            } elseif ($promo->discount_type === 'fixed_amount' || $promo->discount_type === 'product_fixed_discount') {
                $discountAmount = min((float) $promo->discount_value, $subtotal);
            }
            $discountAmount = min($subtotal, round($discountAmount, 2));

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

        // 3. Direct validation: Check Coupon model (coupons table)
        $coupon = Coupon::where('code', $code)->first();
        if ($coupon) {
            if (!$coupon->is_active) {
                return response()->json([
                    'valid' => false,
                    'code' => $code,
                    'error_type' => 'inactive_coupon',
                    'message' => "Coupon '{$code}' is currently inactive.",
                ], 422);
            }
            if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
                return response()->json([
                    'valid' => false,
                    'code' => $code,
                    'error_type' => 'coupon_not_started',
                    'message' => "Coupon '{$code}' campaign has not started yet.",
                ], 422);
            }
            if ($coupon->expires_at && $coupon->expires_at->isPast()) {
                return response()->json([
                    'valid' => false,
                    'code' => $code,
                    'error_type' => 'expired_coupon',
                    'message' => "Coupon '{$code}' has expired.",
                ], 422);
            }
            if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
                return response()->json([
                    'valid' => false,
                    'code' => $code,
                    'error_type' => 'usage_limit_reached',
                    'message' => "The redemption limit for coupon '{$code}' has been reached.",
                ], 422);
            }
            if ($coupon->min_order_amount > 0 && $subtotal < (float) $coupon->min_order_amount) {
                return response()->json([
                    'valid' => false,
                    'code' => $code,
                    'error_type' => 'minimum_order_not_met',
                    'message' => "Minimum order amount of \${$coupon->min_order_amount} required to use this coupon.",
                ], 422);
            }

            $discountAmount = min($subtotal, $coupon->calculateDiscount($subtotal));
            return response()->json([
                'valid' => true,
                'code' => $code,
                'type' => $coupon->type,
                'value' => (float) $coupon->value,
                'discount_amount' => round($discountAmount, 2),
                'promotion_id' => null,
                'promotion_name' => 'Coupon ' . $code,
                'message' => 'Coupon applied successfully!',
            ]);
        }

        return response()->json([
            'valid' => false,
            'code' => $code,
            'error_type' => 'invalid_coupon',
            'message' => "Promo code '{$code}' is invalid.",
        ], 404);
    }
}
