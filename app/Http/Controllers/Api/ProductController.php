<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::with(['category', 'primaryImage', 'images', 'variants'])
            ->active();

        // Filter by category slug(s) or ID(s)
        if ($request->filled('categories') || $request->filled('category')) {
            $catInput = $request->input('categories', $request->input('category'));
            $catSlugs = is_array($catInput) ? $catInput : explode(',', (string) $catInput);
            $catSlugs = array_values(array_filter(array_map('trim', $catSlugs)));
            if (!empty($catSlugs)) {
                $query->whereHas('category', function ($q) use ($catSlugs) {
                    $q->whereIn('slug', $catSlugs)->orWhereIn('id', $catSlugs);
                });
            }
        }

        // Filter by brand slug(s) or name(s)
        if ($request->filled('brands') || $request->filled('brand')) {
            $brandInput = $request->input('brands', $request->input('brand'));
            $brandSlugs = is_array($brandInput) ? $brandInput : explode(',', (string) $brandInput);
            $brandSlugs = array_values(array_filter(array_map('trim', $brandSlugs)));
            if (!empty($brandSlugs)) {
                $matchedNames = \App\Models\Brand::whereIn('slug', $brandSlugs)
                    ->orWhereIn('name', $brandSlugs)
                    ->orWhereIn('id', $brandSlugs)
                    ->pluck('name')
                    ->all();

                $allTargets = array_values(array_unique(array_merge($brandSlugs, $matchedNames)));

                $query->where(function ($q) use ($allTargets) {
                    $q->whereIn('brand', $allTargets);
                    foreach ($allTargets as $t) {
                        $q->orWhere('brand', 'like', "%{$t}%");
                    }
                });
            }
        }

        // Search query
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('short_description', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Price range
        if ($request->filled('min_price')) {
            $query->where('price', '>=', (float) $request->input('min_price'));
        }
        if ($request->filled('max_price')) {
            $query->where('price', '<=', (float) $request->input('max_price'));
        }

        // Rating
        if ($request->filled('min_rating')) {
            $query->where('rating_average', '>=', (float) $request->input('min_rating'));
        }

        // Flags
        if ($request->boolean('featured')) {
            $query->featured();
        }
        if ($request->boolean('new_arrivals')) {
            $query->newArrivals();
        }
        if ($request->boolean('best_sellers')) {
            $query->bestSellers();
        }
        if ($request->boolean('in_stock')) {
            $query->where('stock_quantity', '>', 0);
        }
        if ($request->boolean('discounted') || $request->boolean('on_sale') || $request->boolean('discounted_items')) {
            $query->whereNotNull('compare_at_price')->whereColumn('compare_at_price', '>', 'price');
        }

        // Sorting
        $sort = $request->input('sort', 'popular');
        switch ($sort) {
            case 'price_asc':
                $query->orderBy('price', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('price', 'desc');
                break;
            case 'rating':
                $query->orderBy('rating_average', 'desc');
                break;
            case 'newest':
                $query->latest();
                break;
            case 'popular':
            default:
                $query->orderBy('review_count', 'desc')->orderBy('rating_average', 'desc');
                break;
        }

        $perPage = min((int) $request->input('per_page', 12), 50);
        $products = $query->paginate($perPage);

        return response()->json($products);
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::where('slug', $slug)
            ->orWhere('id', $slug)
            ->with(['category', 'images', 'variants', 'reviews'])
            ->firstOrFail();

        $reviewsSetting = Setting::where('key', 'reviews_enabled')->first();
        $reviewsEnabled = $reviewsSetting ? filter_var($reviewsSetting->value, FILTER_VALIDATE_BOOLEAN) : true;
        if (!$reviewsEnabled) {
            $product->setRelation('reviews', collect([]));
        }

        // Get related products from the same category
        $relatedProducts = Product::where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->with(['primaryImage', 'variants'])
            ->active()
            ->take(4)
            ->get();

        return response()->json([
            'product' => $product,
            'related' => $relatedProducts,
        ]);
    }

    public function featured(): JsonResponse
    {
        $featuredProducts = Product::featured()
            ->with(['category', 'primaryImage', 'images', 'variants'])
            ->take(8)
            ->get();

        $newArrivals = Product::newArrivals()
            ->with(['category', 'primaryImage', 'images', 'variants'])
            ->take(6)
            ->get();

        $bestSellers = Product::bestSellers()
            ->with(['category', 'primaryImage', 'images', 'variants'])
            ->take(6)
            ->get();

        $featuredCategories = Category::where('is_featured', true)
            ->withCount('products')
            ->orderBy('display_order')
            ->get();

        return response()->json([
            'featured_products' => $featuredProducts,
            'new_arrivals' => $newArrivals,
            'best_sellers' => $bestSellers,
            'featured_categories' => $featuredCategories,
        ]);
    }
}
