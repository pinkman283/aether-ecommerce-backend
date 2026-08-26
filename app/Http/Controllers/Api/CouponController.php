<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function validateCoupon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'subtotal' => 'required|numeric|min:0',
        ]);

        $code = strtoupper(trim($validated['code']));
        $subtotal = (float) $validated['subtotal'];

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
            'value' => $coupon->value,
            'discount_amount' => $discount,
            'message' => 'Promo code applied successfully!',
        ]);
    }
}
