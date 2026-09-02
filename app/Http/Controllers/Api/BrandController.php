<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function index(): JsonResponse
    {
        $brands = Brand::withCount('products')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return response()->json($brands);
    }

    public function show(string $slug): JsonResponse
    {
        $brand = Brand::where('slug', $slug)
            ->withCount('products')
            ->with(['products' => function ($q) {
                $q->with('primaryImage');
            }])
            ->firstOrFail();

        return response()->json($brand);
    }
}
