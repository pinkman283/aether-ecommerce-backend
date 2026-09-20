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
     * Only returns banners and promotions that are active and currently within valid schedule windows.
     */
    public function homepage(Request $request): JsonResponse
    {
        $response = \Illuminate\Support\Facades\Cache::remember('storefront_homepage_banners', 300, function () {
            $relations = [
                'promotion:id,name,slug,discount_type,discount_value,badge_text,starts_at,expires_at',
                'promotion.codes:id,promotion_id,code',
                'product:id,name,slug,price,compare_at_price',
                'category:id,name,slug',
                'brand:id,name,slug',
            ];

            // 1. Retrieve all active and scheduled banners
            $banners = Banner::activeAndScheduled()
                ->with($relations)
                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'asc')
                ->get()
                ->map(function ($banner) {
                    if ($banner->promotion_id && $banner->promotion) {
                        $p = $banner->promotion;
                        $promoCode = $p->primary_code;
                        // Authoritative synchronization from promotion
                        $banner->computed_link = '/promotions/' . $p->slug;
                        $banner->cta_link = '/promotions/' . $p->slug;
                        if (!$banner->eyebrow) {
                            $banner->eyebrow = $p->badge_text ?: $p->formatted_discount;
                        }
                        if (!$banner->badge) {
                            $banner->badge = $p->badge_text ?: $p->formatted_discount;
                        }
                        $banner->discount_tag = $promoCode ? "CODE: {$promoCode}" : $p->formatted_discount;
                    }
                    return $banner;
                });

            // 2. Retrieve active promotions configured for storefront presentation
            $storefrontPromos = \App\Models\Promotion::storefrontVisible()
                ->with(['codes' => fn($q) => $q->where('is_active', true)])
                ->get()
                ->map(function ($p) {
                    $promoCode = $p->primary_code;
                    $promoLink = $p->cta_destination ?: ('/promotions/' . $p->slug);

                    return [
                        'id' => 100000 + $p->id,
                        'promotion_id' => $p->id,
                        'title' => $p->headline ?: $p->name,
                        'subtitle' => $p->subheadline ?: $p->description,
                        'eyebrow' => $p->badge_text ?: $p->formatted_discount,
                        'image_url' => $p->banner_image,
                        'mobile_image_url' => $p->mobile_banner_image ?: $p->banner_image,
                        'alt_text' => $p->image_alt_text ?: $p->name,
                        'cta_text' => $p->cta_text ?: 'Shop Now',
                        'cta_link' => $promoLink,
                        'computed_link' => $promoLink,
                        'destination_type' => 'promotion',
                        'destination_id' => $p->id,
                        'placement' => $p->storefront_placement ?: 'primary_hero',
                        'badge' => $p->badge_text ?: $p->formatted_discount,
                        'discount_tag' => $promoCode ? "CODE: {$promoCode}" : $p->formatted_discount,
                        'sort_order' => 0,
                        'is_active' => true,
                        'is_currently_visible' => true,
                        'clicks_count' => 0,
                        'impressions_count' => 0,
                        'promotion' => [
                            'id' => $p->id,
                            'name' => $p->name,
                            'slug' => $p->slug,
                            'discount_type' => $p->discount_type,
                            'discount_value' => (float) $p->discount_value,
                            'badge_text' => $p->badge_text,
                            'starts_at' => $p->starts_at?->toISOString(),
                            'expires_at' => $p->expires_at?->toISOString(),
                            'codes' => $p->codes->map(fn($c) => ['id' => $c->id, 'promotion_id' => $p->id, 'code' => $c->code]),
                        ],
                    ];
                });

            // Deduplicate: If an explicit Banner already links to a promotion_id on a placement,
            // that explicit Banner takes precedence, preventing duplicate display of the same promotion.
            $existingPromoIdsByPlacement = [];
            foreach ($banners as $b) {
                if ($b->promotion_id) {
                    $normPlacement = in_array($b->placement, ['primary_hero', 'hero_slider']) ? 'primary_hero' :
                        (in_array($b->placement, ['bottom_banner', 'middle_promo', 'discount_carousel']) ? 'bottom_banner' :
                        (in_array($b->placement, ['top_strip', 'top_announcement']) ? 'top_strip' : $b->placement));
                    $existingPromoIdsByPlacement[$normPlacement . '_' . $b->promotion_id] = true;
                }
            }

            // Exclude auto-generated storefront promotions if an explicit banner already represents that promotion on that placement
            $filteredStorefrontPromos = $storefrontPromos->reject(function ($p) use ($existingPromoIdsByPlacement) {
                $normPlacement = in_array($p['placement'], ['primary_hero', 'hero_slider', 'hero_carousel']) ? 'primary_hero' :
                    (in_array($p['placement'], ['bottom_banner', 'middle_promo', 'discount_carousel', 'voucher_carousel']) ? 'bottom_banner' :
                    (in_array($p['placement'], ['top_strip', 'top_announcement']) ? 'top_strip' : $p['placement']));
                return isset($existingPromoIdsByPlacement[$normPlacement . '_' . $p['promotion_id']]);
            });

            // Merge deduplicated promotions into banners
            $allBanners = $banners->concat($filteredStorefrontPromos);

            // Segment by placement
            $primaryBanners = $allBanners->filter(function ($b) {
                $placement = is_array($b) ? ($b['placement'] ?? '') : $b->placement;
                return in_array($placement, ['primary_hero', 'hero_slider']);
            })->values();

            $secondaryBanners = $allBanners->filter(function ($b) {
                $placement = is_array($b) ? ($b['placement'] ?? '') : $b->placement;
                return in_array($placement, ['secondary_hero']);
            })->values();

            $bottomBanners = $allBanners->filter(function ($b) {
                $placement = is_array($b) ? ($b['placement'] ?? '') : $b->placement;
                return in_array($placement, ['bottom_banner', 'middle_promo', 'discount_carousel']);
            })->values();

            $topStrip = $allBanners->first(function ($b) {
                $placement = is_array($b) ? ($b['placement'] ?? '') : $b->placement;
                return in_array($placement, ['top_strip', 'top_announcement']);
            });

            return [
                'primary_banners' => $primaryBanners,
                'secondary_banners' => $secondaryBanners,
                'bottom_banners' => $bottomBanners,
                'middle_banners' => $bottomBanners,
                'top_strip' => $topStrip,
                'all_active_count' => $allBanners->count(),
            ];
        });

        return response()->json($response);
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
