<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(Request $request, int $productId): JsonResponse
    {
        $product = Product::findOrFail($productId);
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:255',
            'comment' => 'required|string|min:5|max:2000',
        ]);

        // Server-side check if user actually purchased and paid for this item
        $isVerifiedPurchase = $user->orders()
            ->where('payment_status', 'paid')
            ->whereHas('items', function ($q) use ($product) {
                $q->where('product_id', $product->id);
            })
            ->exists();

        $review = Review::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_avatar' => $user->avatar ?? 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?auto=format&fit=crop&w=150&q=80',
            'rating' => $validated['rating'],
            'title' => $validated['title'] ?? null,
            'comment' => $validated['comment'],
            'is_verified_purchase' => $isVerifiedPurchase,
            'is_approved' => true,
        ]);

        // Recalculate average rating & review count
        $avgRating = $product->reviews()->where('is_approved', true)->avg('rating') ?: 5.0;
        $count = $product->reviews()->where('is_approved', true)->count();

        $product->update([
            'rating_average' => round($avgRating, 2),
            'review_count' => $count,
        ]);

        return response()->json([
            'message' => 'Review submitted successfully',
            'review' => $review,
        ], 201);
    }
}
