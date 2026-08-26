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
        $user = $request->user('sanctum');

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:255',
            'comment' => 'required|string|min:5',
            'user_name' => 'nullable|string|max:255',
        ]);

        $userName = $user ? $user->name : ($validated['user_name'] ?? 'Verified Customer');
        $userAvatar = $user?->avatar ?? 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?auto=format&fit=crop&w=150&q=80';

        $review = Review::create([
            'product_id' => $product->id,
            'user_id' => $user?->id,
            'user_name' => $userName,
            'user_avatar' => $userAvatar,
            'rating' => $validated['rating'],
            'title' => $validated['title'] ?? null,
            'comment' => $validated['comment'],
            'is_verified_purchase' => true,
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
