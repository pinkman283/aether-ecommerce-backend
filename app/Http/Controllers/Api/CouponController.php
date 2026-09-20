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

        // Promotion + PromotionCode is the authoritative active system.
        // Legacy Coupon records remain in the database solely for historical order auditing.
        return response()->json([
            'valid' => false,
            'message' => 'Invalid promo code.',
        ], 404);
    }
}
