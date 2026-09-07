<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminReviewController extends Controller
{
    /**
     * Analytics and moderation overview summary.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'reviews.manage', 'products.view');

        $total = Review::count();
        $approved = Review::where('is_approved', true)->count();
        $pending = Review::where('is_approved', false)->count();

        $approvedReviews = Review::where('is_approved', true);
        $avgRating = $approved > 0 ? round($approvedReviews->avg('rating'), 2) : 0.00;

        $ratingDistribution = [
            5 => Review::where('rating', 5)->count(),
            4 => Review::where('rating', 4)->count(),
            3 => Review::where('rating', 3)->count(),
            2 => Review::where('rating', 2)->count(),
            1 => Review::where('rating', 1)->count(),
        ];

        return response()->json([
            'total_reviews' => $total,
            'approved_reviews' => $approved,
            'pending_reviews' => $pending,
            'average_rating' => $avgRating,
            'rating_distribution' => $ratingDistribution,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'reviews.manage', 'products.view');

        $query = Review::with('product')->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('user_name', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%")
                  ->orWhere('comment', 'like', "%{$search}%");
            });
        }

        if ($request->filled('rating')) {
            $query->where('rating', (int) $request->input('rating'));
        }

        if ($request->filled('status')) {
            if ($request->input('status') === 'approved') {
                $query->where('is_approved', true);
            } elseif ($request->input('status') === 'pending') {
                $query->where('is_approved', false);
            }
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $reviews = $query->paginate($perPage);

        return response()->json($reviews);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'reviews.manage');

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'user_name' => 'required|string|max:100',
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:255',
            'comment' => 'required|string',
            'is_verified_purchase' => 'boolean',
            'is_approved' => 'boolean',
        ]);

        $review = Review::create([
            'product_id' => $validated['product_id'],
            'user_id' => $request->user()->id,
            'user_name' => trim($validated['user_name']),
            'rating' => $validated['rating'],
            'title' => $validated['title'] ?? null,
            'comment' => $validated['comment'],
            'is_verified_purchase' => $validated['is_verified_purchase'] ?? true,
            'is_approved' => $validated['is_approved'] ?? true,
        ]);

        $this->recalculateProductRating($validated['product_id']);

        AuditLog::log(
            $request->user(),
            'review.created',
            'Review',
            $review->id,
            "Created review #{$review->id} for Product #{$review->product_id} by {$review->user_name} (Rating: {$review->rating}/5)",
            null,
            $review->toArray()
        );

        return response()->json([
            'message' => 'Review created successfully.',
            'review' => $review->load('product'),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'reviews.manage');

        $review = Review::with('product')->findOrFail($id);
        $oldValues = $review->toArray();

        $validated = $request->validate([
            'user_name' => 'required|string|max:100',
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:255',
            'comment' => 'required|string',
            'is_verified_purchase' => 'boolean',
            'is_approved' => 'boolean',
        ]);

        $review->update($validated);

        $this->recalculateProductRating($review->product_id);

        AuditLog::log(
            $request->user(),
            'review.updated',
            'Review',
            $review->id,
            "Updated review #{$review->id} for Product #{$review->product_id}",
            $oldValues,
            $review->toArray()
        );

        return response()->json([
            'message' => 'Review updated successfully.',
            'review' => $review->fresh('product'),
        ]);
    }

    public function toggleApproval(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'reviews.manage');

        $review = Review::with('product')->findOrFail($id);
        $newStatus = !$review->is_approved;

        $review->update(['is_approved' => $newStatus]);
        $this->recalculateProductRating($review->product_id);

        AuditLog::log(
            $request->user(),
            'review.moderated',
            'Review',
            $review->id,
            "Review #{$review->id} for '{$review->product?->name}' by {$review->user_name} marked as " . ($newStatus ? 'approved' : 'hidden') . "."
        );

        return response()->json([
            'message' => "Review " . ($newStatus ? 'approved' : 'hidden') . " successfully.",
            'review' => $review,
        ]);
    }

    public function bulkApprove(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'reviews.manage');

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:reviews,id',
        ]);

        Review::whereIn('id', $validated['ids'])->update(['is_approved' => true]);

        $affectedProductIds = Review::whereIn('id', $validated['ids'])->pluck('product_id')->unique();
        foreach ($affectedProductIds as $pId) {
            $this->recalculateProductRating($pId);
        }

        return response()->json([
            'message' => "Approved " . count($validated['ids']) . " review(s).",
        ]);
    }

    public function bulkReject(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'reviews.manage');

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:reviews,id',
        ]);

        Review::whereIn('id', $validated['ids'])->update(['is_approved' => false]);

        $affectedProductIds = Review::whereIn('id', $validated['ids'])->pluck('product_id')->unique();
        foreach ($affectedProductIds as $pId) {
            $this->recalculateProductRating($pId);
        }

        return response()->json([
            'message' => "Marked " . count($validated['ids']) . " review(s) as pending/hidden.",
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'reviews.manage');

        $review = Review::with('product')->findOrFail($id);
        $productId = $review->product_id;
        $author = $review->user_name;

        $review->delete();
        $this->recalculateProductRating($productId);

        AuditLog::log(
            $request->user(),
            'review.deleted',
            'Review',
            $id,
            "Deleted review #{$id} by {$author}."
        );

        return response()->json(['message' => 'Review deleted successfully.']);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'reviews.manage');

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:reviews,id',
        ]);

        $affectedProductIds = Review::whereIn('id', $validated['ids'])->pluck('product_id')->unique();
        Review::whereIn('id', $validated['ids'])->delete();

        foreach ($affectedProductIds as $pId) {
            $this->recalculateProductRating($pId);
        }

        return response()->json([
            'message' => "Successfully deleted " . count($validated['ids']) . " review(s).",
        ]);
    }

    private function recalculateProductRating(?int $productId): void
    {
        if (!$productId) return;

        $approvedReviews = Review::where('product_id', $productId)->where('is_approved', true);
        $count = $approvedReviews->count();
        $avg = $count > 0 ? round($approvedReviews->avg('rating'), 2) : 0.00;

        Product::where('id', $productId)->update([
            'rating_average' => $avg,
            'review_count' => $count,
        ]);
    }
}
