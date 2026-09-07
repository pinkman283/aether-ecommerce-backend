<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HomepageSection;
use App\Services\HomepageSectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomepageSectionController extends Controller
{
    protected HomepageSectionService $sectionService;

    public function __construct(HomepageSectionService $sectionService)
    {
        $this->sectionService = $sectionService;
    }

    /**
     * Get all active homepage sections with pre-resolved products.
     */
    public function index(): JsonResponse
    {
        $sections = $this->sectionService->getActiveSections();

        return response()->json([
            'success' => true,
            'data' => $sections,
        ]);
    }

    /**
     * Fetch products for a specific tab of a section on-demand.
     */
    public function tabProducts(Request $request, int $id): JsonResponse
    {
        $section = HomepageSection::findOrFail($id);
        $tabId = $request->query('tab_id');

        $tab = collect($section->tabs)->firstWhere('id', $tabId);
        if (!$tab) {
            return response()->json([
                'success' => false,
                'message' => 'Tab not found',
            ], 404);
        }

        $config = [
            'source_type' => $tab['source_type'] ?? 'category',
            'category_id' => $tab['category_id'] ?? $section->category_id,
            'brand_id' => $tab['brand_id'] ?? $section->brand_id,
            'sort_by' => $tab['sort_by'] ?? 'featured',
            'product_ids' => $tab['product_ids'] ?? [],
        ];

        $products = $this->sectionService->resolveProducts($config, $section->product_count ?: 14);

        return response()->json([
            'success' => true,
            'tab_id' => $tabId,
            'products' => $products,
        ]);
    }
}
