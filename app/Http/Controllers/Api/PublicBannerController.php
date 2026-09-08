<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicBannerController extends Controller
{
    /**
     * Retrieve authoritative storefront homepage promotional banners.
     * Only returns banners that are active and currently within valid schedule windows.
     */
    public function homepage(Request $request): JsonResponse
    {
        $relations = [
            'promotion:id,name,slug,discount_type,discount_value,badge_text,starts_at,expires_at',
            'promotion.codes:id,promotion_id,code',
            'product:id,name,slug,price,compare_at_price',
            'category:id,name,slug',
            'brand:id,name,slug',
        ];

        // Retrieve all active and scheduled banners
        $banners = Banner::activeAndScheduled()
            ->with($relations)
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // Segment by placement
        $primaryBanners = $banners->filter(function ($b) {
            return in_array($b->placement, ['primary_hero', 'hero_slider']);
        })->values();

        $secondaryBanners = $banners->filter(function ($b) {
            return in_array($b->placement, ['secondary_hero']);
        })->values();

        $bottomBanners = $banners->filter(function ($b) {
            return in_array($b->placement, ['bottom_banner', 'middle_promo', 'discount_carousel']);
        })->values();

        $topStrip = $banners->first(function ($b) {
            return in_array($b->placement, ['top_strip', 'top_announcement']);
        });

        return response()->json([
            'primary_banners' => $primaryBanners,
            'secondary_banners' => $secondaryBanners,
            'bottom_banners' => $bottomBanners,
            'middle_banners' => $bottomBanners,
            'top_strip' => $topStrip,
            'all_active_count' => $banners->count(),
        ]);
    }

    /**
     * Track a customer click on a specific banner.
     */
    public function trackClick(int $id): JsonResponse
    {
        $banner = Banner::find($id);

        if ($banner) {
            $banner->increment('clicks_count');
            return response()->json([
                'success' => true,
                'clicks_count' => $banner->clicks_count,
            ]);
        }

        return response()->json(['success' => false, 'message' => 'Banner not found'], 404);
    }
}
