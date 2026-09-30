<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerWishlistItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerWishlistController extends Controller
{
    /**
     * Get authenticated customer's persistent server wishlist.
     */
    public function getWishlist(Request $request): JsonResponse
    {
        $user = $request->user();
        $items = CustomerWishlistItem::where('user_id', $user->id)
            ->with(['product.primaryImage', 'product.images', 'product.category'])
            ->get();

        $validProducts = [];
        foreach ($items as $item) {
            $product = $item->product;

            // Prune deleted or inactive products
            if (!$product || !$product->is_active || ($product->status && $product->status !== 'active')) {
                $item->delete();
                continue;
            }

            $validProducts[] = [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'sku' => $product->sku,
                'price' => (float) $product->price,
                'is_active' => (bool) $product->is_active,
                'stock_quantity' => (int) $product->stock_quantity,
                'primary_image' => $product->primaryImage?->image_url,
                'category' => $product->category ? [
                    'id' => $product->category->id,
                    'name' => $product->category->name,
                    'slug' => $product->category->slug,
                ] : null,
            ];
        }

        return response()->json([
            'products' => $validProducts,
            'total' => count($validProducts),
        ]);
    }

    /**
     * Toggle product in authenticated customer's wishlist.
     */
    public function toggleWishlist(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
        ]);

        $user = $request->user();
        $productId = (int) $validated['product_id'];

        $product = Product::findOrFail($productId);
        if (!$product->is_active || ($product->status && $product->status !== 'active')) {
            return response()->json([
                'message' => 'This product is currently inactive and cannot be saved to your wishlist.',
            ], 422);
        }

        $existing = CustomerWishlistItem::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->first();

        if ($existing) {
            $existing->delete();
            return response()->json([
                'message' => 'Product removed from your wishlist.',
                'in_wishlist' => false,
                'product_id' => $productId,
            ]);
        }

        CustomerWishlistItem::create([
            'user_id' => $user->id,
            'product_id' => $productId,
        ]);

        return response()->json([
            'message' => 'Product added to your wishlist.',
            'in_wishlist' => true,
            'product_id' => $productId,
        ]);
    }

    /**
     * Merge guest wishlist with customer's persistent server wishlist.
     */
    public function mergeWishlist(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'guest_product_ids' => 'present|array',
            'guest_product_ids.*' => 'required|integer',
        ]);

        $user = $request->user();
        $guestProductIds = array_unique(array_map('intval', $validated['guest_product_ids']));

        if (!empty($guestProductIds)) {
            // Validate which guest products actually exist and are active
            $validProducts = Product::whereIn('id', $guestProductIds)
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();

            foreach ($validProducts as $productId) {
                CustomerWishlistItem::firstOrCreate([
                    'user_id' => $user->id,
                    'product_id' => $productId,
                ]);
            }
        }

        return $this->getWishlist($request);
    }

    /**
     * Clear customer wishlist.
     */
    public function clearWishlist(Request $request): JsonResponse
    {
        $user = $request->user();
        CustomerWishlistItem::where('user_id', $user->id)->delete();

        return response()->json([
            'message' => 'Wishlist cleared successfully.',
            'products' => [],
            'total' => 0,
        ]);
    }
}
