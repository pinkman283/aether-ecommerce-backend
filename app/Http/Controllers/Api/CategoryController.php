<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::withCount('products')
            ->with(['children' => fn($q) => $q->withCount('products')])
            ->whereNull('parent_id')
            ->orderBy('display_order')
            ->get();

        return response()->json($categories);
    }

    public function show(string $slug): JsonResponse
    {
        $category = Category::where('slug', $slug)
            ->orWhere('id', $slug)
            ->withCount('products')
            ->with(['products' => fn($q) => $q->with(['primaryImage', 'variants'])->take(12)])
            ->firstOrFail();

        return response()->json($category);
    }
}
