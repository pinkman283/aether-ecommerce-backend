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
            $query->where('rating', $request->input('rating'));
        }

        if ($request->filled('status')) {
            if ($request->input('status') === 'approved') {
                $query->where('is_approved', true);
            } elseif ($request->input('status') === 'pending') {
                $query->where('is_approved', false);
            }
        }

        $perPage = (int) $request->input('per_page', 15);
        $reviews = $query->paginate($perPage);

        return response()->json($reviews);
    }

    public function toggleApproval(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'reviews.manage');

        $review = Review::with('product')->findOrFail($id);
        $newStatus = !$review->is_approved;

        $review->update(['is_approved' => $newStatus]);

        // Recalculate product rating average
        $product = $review->product;
        if ($product) {
            $approvedReviews = Review::where('product_id', $product->id)->where('is_approved', true);
            $count = $approvedReviews->count();
            $avg = $count > 0 ? round($approvedReviews->avg('rating'), 2) : 5.0;
            $product->update([
                'rating_average' => $avg,
                'review_count' => $count,
            ]);
        }

        AuditLog::log(
            $request->user(),
            'review.moderated',
            'Review',
            $review->id,
            "Review #{$review->id} for '{$product?->name}' by {$review->user_name} marked as " . ($newStatus ? 'approved' : 'rejected') . "."
        );

        return response()->json([
            'message' => "Review " . ($newStatus ? 'approved' : 'hidden') . " successfully.",
            'review' => $review,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'reviews.manage');

        $review = Review::with('product')->findOrFail($id);
        $product = $review->product;
        $author = $review->user_name;

        $review->delete();

        if ($product) {
            $approvedReviews = Review::where('product_id', $product->id)->where('is_approved', true);
            $count = $approvedReviews->count();
            $avg = $count > 0 ? round($approvedReviews->avg('rating'), 2) : 5.0;
            $product->update([
                'rating_average' => $avg,
                'review_count' => $count,
            ]);
        }

        AuditLog::log(
            $request->user(),
            'review.deleted',
            'Review',
            $id,
            "Deleted review #{$id} by {$author} on '{$product?->name}'."
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

        $count = 0;
        $affectedProductIds = [];

        foreach ($validated['ids'] as $id) {
            $review = Review::find($id);
            if ($review) {
                $productId = $review->product_id;
                $author = $review->user_name;
                $review->delete();
                $count++;

                if ($productId) {
                    $affectedProductIds[$productId] = true;
                }

                AuditLog::log(
                    $request->user(),
                    'review.deleted',
                    'Review',
                    $id,
                    "Bulk deleted review #{$id} by {$author}."
                );
            }
        }

        // Recalculate ratings for affected products
        foreach (array_keys($affectedProductIds) as $pId) {
            $approvedReviews = Review::where('product_id', $pId)->where('is_approved', true);
            $pCount = $approvedReviews->count();
            $avg = $pCount > 0 ? round($approvedReviews->avg('rating'), 2) : 5.0;
            Product::where('id', $pId)->update([
                'rating_average' => $avg,
                'review_count' => $pCount,
            ]);
        }

        return response()->json([
            'message' => "Successfully deleted {$count} review(s).",
            'deleted_count' => $count,
        ]);
    }
}
