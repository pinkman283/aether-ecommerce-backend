<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Models\PromotionClaim;
use App\Models\PromotionCode;
use App\Models\PromotionRedemption;
use App\Models\StoreCreditAccount;
use App\Models\StoreCreditTransaction;
use App\Services\PromotionEngine;
use App\Services\StoreCreditService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PromotionController extends Controller
{
    /**
     * Authoritative Cart Promotion Evaluation Endpoint.
     * Evaluates automatic discounts, discount codes, claimable coupons, and store credit.
     */
    public function evaluate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.product_id' => 'required|integer',
            'items.*.variant_id' => 'nullable|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'code' => 'nullable|string',
            'claimed_coupon_id' => 'nullable|integer',
            'shipping_rate' => 'nullable|numeric|min:0',
            'shipping_method' => 'nullable|string',
            'payment_method' => 'nullable|string',
            'customer_email' => 'nullable|email',
        ]);

        $user = $request->user('sanctum');
        $customerEmail = $validated['customer_email'] ?? $user?->email;

        // Dynamic resolution of shipping rate:
        // If explicitly supplied (including 0), use it.
        // If omitted but shipping_method provided, calculate from shipping zones.
        // Otherwise default to 0.00 (no delivery charge until zone selected).
        if (isset($validated['shipping_rate'])) {
            $baseShippingRate = (float) $validated['shipping_rate'];
        } elseif (!empty($validated['shipping_method'])) {
            $approxSubtotal = 0.00;
            $productIds = array_column($validated['items'], 'product_id');
            $products = \App\Models\Product::whereIn('id', $productIds)->get()->keyBy('id');
            foreach ($validated['items'] as $it) {
                $p = $products->get($it['product_id']);
                if ($p) {
                    $approxSubtotal += (float) $p->price * (int) $it['quantity'];
                }
            }
            $baseShippingRate = OrderController::calculateAuthoritativeShippingRate($validated['shipping_method'], $approxSubtotal);
        } else {
            $baseShippingRate = 0.00;
        }

        $paymentMethod = $validated['payment_method'] ?? 'cash_on_delivery';

        $result = PromotionEngine::evaluateCart(
            $validated['items'],
            $user,
            $customerEmail,
            $validated['code'] ?? null,
            $validated['claimed_coupon_id'] ?? null,
            $baseShippingRate,
            $paymentMethod
        );

        // Append customer store credit information if authenticated
        $storeCreditBalance = 0.00;
        if ($user) {
            $storeCreditBalance = StoreCreditService::getBalance($user);
        }
        $result['customer_store_credit_balance'] = $storeCreditBalance;

        $statusCode = $result['valid'] ? 200 : 422;
        return response()->json($result, $statusCode);
    }

    /**
     * Get all active claimable promotions for storefront displays and customer claiming.
     */
    public function claimable(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');

        // Auto-deactivate promotions whose expiration date or claim deadline has passed
        Promotion::where('status', 'active')
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->whereNotNull('expires_at')->where('expires_at', '<', now());
                })->orWhere(function ($sub) {
                    $sub->whereNotNull('claim_deadline')->where('claim_deadline', '<', now());
                });
            })
            ->update(['status' => 'expired']);

        $promotions = Promotion::where('promotion_type', 'claimable_coupon')
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->where(function ($q) {
                $q->whereNull('claim_deadline')->orWhere('claim_deadline', '>=', now());
            })
            ->with(['codes', 'productTargets'])
            ->orderBy('priority', 'desc')
            ->get();

        // Check if user has already claimed each coupon
        $userClaimMap = [];
        if ($user) {
            $claims = PromotionClaim::where('user_id', $user->id)
                ->whereIn('promotion_id', $promotions->pluck('id'))
                ->get();
            foreach ($claims as $c) {
                $userClaimMap[$c->promotion_id][] = $c;
            }
        }

        $formatted = $promotions->map(function ($promo) use ($userClaimMap) {
            $userClaims = $userClaimMap[$promo->id] ?? [];
            $activeClaim = collect($userClaims)->first(function ($c) {
                return $c->status === 'claimed' && (!$c->expires_at || $c->expires_at->isFuture());
            });

            $alreadyClaimed = !empty($activeClaim);
            $hasReachedLimit = count($userClaims) >= ($promo->per_customer_usage_limit ?: 1);

            return [
                'id' => $promo->id,
                'name' => $promo->name,
                'slug' => $promo->slug,
                'description' => $promo->description,
                'discount_type' => $promo->discount_type,
                'discount_value' => (float) $promo->discount_value,
                'max_discount_amount' => $promo->max_discount_amount ? (float) $promo->max_discount_amount : null,
                'min_order_amount' => (float) $promo->min_order_amount,
                'applies_to' => $promo->applies_to,
                'claim_validity_days' => $promo->claim_validity_days,
                'expires_at' => $promo->expires_at?->toISOString(),
                'claim_deadline' => $promo->claim_deadline?->toISOString(),
                'badge_text' => $promo->badge_text,
                'banner_image' => $promo->banner_image,
                'thumbnail_image' => $promo->thumbnail_image,
                'cta_text' => $promo->cta_text,
                'cta_destination' => $promo->cta_destination,
                'is_claimed' => $alreadyClaimed,
                'claimed_id' => $activeClaim?->id,
                'claim_expires_at' => $activeClaim?->expires_at?->toISOString(),
                'can_claim' => !$hasReachedLimit && $promo->isClaimable(),
            ];
        });

        return response()->json($formatted);
    }

    /**
     * Claim a claimable coupon for the authenticated customer.
     */
    public function claim(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Please sign in to claim coupons.'], 401);
        }

        $validated = $request->validate([
            'promotion_id' => 'required|integer|exists:promotions,id',
        ]);

        return DB::transaction(function () use ($user, $validated) {
            $promo = Promotion::where('id', $validated['promotion_id'])->lockForUpdate()->firstOrFail();

            if (!$promo->isClaimable() || ($promo->expires_at && $promo->expires_at->isPast()) || ($promo->claim_deadline && $promo->claim_deadline->isPast())) {
                if ($promo->status === 'active') {
                    $promo->update(['status' => 'expired']);
                }
                return response()->json(['message' => 'This promotion has expired or its claim period has ended.'], 422);
            }

            // Check per-customer claim limit
            $existingClaimCount = PromotionClaim::where('promotion_id', $promo->id)
                ->where('user_id', $user->id)
                ->count();

            $limit = $promo->per_customer_usage_limit ?: 1;
            if ($existingClaimCount >= $limit) {
                return response()->json([
                    'message' => "You have already claimed this coupon the maximum allowed times ({$limit}).",
                ], 422);
            }

            // Calculate expiration date
            $expiresAt = null;
            if ($promo->claim_validity_days) {
                $expiresAt = now()->addDays($promo->claim_validity_days);
                if ($promo->expires_at && $expiresAt->greaterThan($promo->expires_at)) {
                    $expiresAt = $promo->expires_at;
                }
            } elseif ($promo->expires_at) {
                $expiresAt = $promo->expires_at;
            }

            // Determine code
            $code = $promo->codes()->first()?->code ?? strtoupper($promo->slug);

            $claim = PromotionClaim::create([
                'promotion_id' => $promo->id,
                'user_id' => $user->id,
                'claimed_code' => $code,
                'status' => 'claimed',
                'claimed_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            $promo->increment('total_claimed_count');

            return response()->json([
                'message' => "Coupon '{$promo->name}' claimed successfully!",
                'claim' => $claim->load('promotion'),
            ], 201);
        });
    }

    /**
     * Customer "My Coupons" endpoint:
     * Returns categorized available, claimed, used, and expired coupons.
     */
    public function myCoupons(Request $request): JsonResponse
    {
        $user = $request->user();

        // Auto-deactivate promotions whose expiration date or claim deadline has passed
        Promotion::where('status', 'active')
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->whereNotNull('expires_at')->where('expires_at', '<', now());
                })->orWhere(function ($sub) {
                    $sub->whereNotNull('claim_deadline')->where('claim_deadline', '<', now());
                });
            })
            ->update(['status' => 'expired']);

        // 1. All user claims
        $claims = PromotionClaim::where('user_id', $user->id)
            ->with(['promotion', 'order'])
            ->latest('claimed_at')
            ->get();

        $claimed = [];
        $used = [];
        $expired = [];

        foreach ($claims as $claim) {
            $promo = $claim->promotion;
            $isExpired = ($claim->status === 'expired') || ($claim->expires_at && $claim->expires_at->isPast() && $claim->status === 'claimed');

            $item = [
                'claim_id' => $claim->id,
                'promotion_id' => $promo?->id,
                'name' => $promo?->name ?? 'Special Reward',
                'description' => $promo?->description,
                'code' => $claim->claimed_code,
                'discount_type' => $promo?->discount_type ?? 'percentage',
                'discount_value' => $promo ? (float) $promo->discount_value : 0.00,
                'min_order_amount' => $promo ? (float) $promo->min_order_amount : 0.00,
                'max_discount_amount' => $promo?->max_discount_amount ? (float) $promo->max_discount_amount : null,
                'status' => $isExpired ? 'expired' : $claim->status,
                'claimed_at' => $claim->claimed_at?->toISOString(),
                'expires_at' => $claim->expires_at?->toISOString(),
                'redeemed_at' => $claim->redeemed_at?->toISOString(),
                'order_number' => $claim->order?->order_number,
                'badge_text' => $promo?->badge_text,
                'thumbnail_image' => $promo?->thumbnail_image,
            ];

            if ($claim->status === 'redeemed') {
                $used[] = $item;
            } elseif ($isExpired) {
                $expired[] = $item;
            } else {
                $claimed[] = $item;
            }
        }

        // 2. Discover available claimable promotions that user hasn't claimed yet
        $alreadyClaimedPromoIds = $claims->pluck('promotion_id')->filter()->unique()->toArray();
        $availablePromos = Promotion::where('promotion_type', 'claimable_coupon')
            ->where('status', 'active')
            ->whereNotIn('id', $alreadyClaimedPromoIds)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->where(function ($q) {
                $q->whereNull('claim_deadline')->orWhere('claim_deadline', '>=', now());
            })
            ->get()
            ->map(function ($promo) {
                return [
                    'promotion_id' => $promo->id,
                    'name' => $promo->name,
                    'description' => $promo->description,
                    'discount_type' => $promo->discount_type,
                    'discount_value' => (float) $promo->discount_value,
                    'min_order_amount' => (float) $promo->min_order_amount,
                    'claim_validity_days' => $promo->claim_validity_days,
                    'expires_at' => $promo->expires_at?->toISOString(),
                    'badge_text' => $promo->badge_text,
                    'thumbnail_image' => $promo->thumbnail_image,
                    'can_claim' => true,
                ];
            });

        return response()->json([
            'claimed' => $claimed,
            'available' => $availablePromos,
            'used' => $used,
            'expired' => $expired,
        ]);
    }

    /**
     * Customer Store Credit Account & Transaction Ledger.
     */
    public function storeCredit(Request $request): JsonResponse
    {
        $user = $request->user();
        $account = StoreCreditService::getOrCreateAccount($user);

        $transactions = StoreCreditTransaction::where('user_id', $user->id)
            ->latest()
            ->paginate(20);

        return response()->json([
            'balance' => (float) $account->balance,
            'total_credited' => (float) $account->total_credited,
            'total_debited' => (float) $account->total_debited,
            'is_frozen' => (bool) $account->is_frozen,
            'transactions' => $transactions,
        ]);
    }

    /**
     * Public Storefront Promotions Endpoint:
     * Returns active promotions configured for storefront presentation.
     */
    public function storefrontPromotions(Request $request): JsonResponse
    {
        $placement = $request->query('placement'); // primary_hero, secondary_hero, top_strip, bottom_banner, flash_sale
        $cacheKey = 'storefront_promotions_' . ($placement ?: 'all');

        $promotions = \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () use ($placement) {
            $query = Promotion::storefrontVisible($placement ? (string) $placement : null)
                ->with(['codes' => fn($q) => $q->where('is_active', true)]);

            return $query->get()->map(function ($promo) {
                return [
                    'id' => $promo->id,
                    'name' => $promo->name,
                    'slug' => $promo->slug,
                    'headline' => $promo->headline ?: $promo->name,
                    'subheadline' => $promo->subheadline ?: $promo->description,
                    'description' => $promo->description,
                    'badge_text' => $promo->badge_text ?: $promo->formatted_discount,
                    'image_alt_text' => $promo->image_alt_text ?: $promo->name,
                    'banner_image' => $promo->banner_image,
                    'mobile_banner_image' => $promo->mobile_banner_image ?: $promo->banner_image,
                    'cta_text' => $promo->cta_text ?: 'Shop Now',
                    'cta_destination' => $promo->cta_destination ?: ('/promotions/' . $promo->slug),
                    'computed_link' => $promo->cta_destination ?: ('/promotions/' . $promo->slug),
                    'storefront_placement' => $promo->storefront_placement,
                    'promotion_type' => $promo->promotion_type,
                    'discount_type' => $promo->discount_type,
                    'discount_value' => (float) $promo->discount_value,
                    'formatted_discount' => $promo->formatted_discount,
                    'min_order_amount' => (float) $promo->min_order_amount,
                    'max_discount_amount' => $promo->max_discount_amount ? (float) $promo->max_discount_amount : null,
                    'primary_code' => $promo->primary_code,
                    'starts_at' => $promo->starts_at?->toISOString(),
                    'expires_at' => $promo->expires_at?->toISOString(),
                    'terms_conditions' => $promo->terms_conditions,
                    'is_active' => true,
                ];
            });
        });

        return response()->json($promotions);
    }

    /**
     * Public Campaign Landing Page Endpoint:
     * Returns full promotion details and eligible products for /promotions/[slug].
     */
    public function campaignDetails(string $slug): JsonResponse
    {
        $promo = Promotion::where('slug', $slug)
            ->orWhere('id', is_numeric($slug) ? (int) $slug : 0)
            ->with(['codes' => fn($q) => $q->where('is_active', true), 'productTargets'])
            ->first();

        if (!$promo) {
            return response()->json([
                'found' => false,
                'message' => 'Campaign not found.',
            ], 404);
        }

        $isActive = $promo->isScheduleActive();

        // Hydrate eligible products
        $products = [];
        if ($isActive) {
            $productQuery = \App\Models\Product::where('is_active', true)->with(['category', 'primaryImage', 'images']);

            if ($promo->applies_to === 'specific_products') {
                $targetIds = $promo->productTargets->where('target_type', 'product')->pluck('target_id');
                $productQuery->whereIn('id', $targetIds);
            } elseif ($promo->applies_to === 'specific_categories') {
                $categoryIds = $promo->productTargets->where('target_type', 'category')->pluck('target_id');
                $productQuery->whereIn('category_id', $categoryIds);
            } elseif ($promo->applies_to === 'specific_brands') {
                $brandIds = $promo->productTargets->where('target_type', 'brand')->pluck('target_id');
                $productQuery->whereIn('brand_id', $brandIds);
            } else {
                // Entire order / all products: return featured products
                $productQuery->latest();
            }

            $products = $productQuery->take(24)->get();
        }

        return response()->json([
            'found' => true,
            'is_active' => $isActive,
            'promotion' => [
                'id' => $promo->id,
                'name' => $promo->name,
                'slug' => $promo->slug,
                'headline' => $promo->headline ?: $promo->name,
                'subheadline' => $promo->subheadline ?: $promo->description,
                'description' => $promo->description,
                'badge_text' => $promo->badge_text ?: $promo->formatted_discount,
                'image_alt_text' => $promo->image_alt_text ?: $promo->name,
                'banner_image' => $promo->banner_image,
                'mobile_banner_image' => $promo->mobile_banner_image ?: $promo->banner_image,
                'cta_text' => $promo->cta_text ?: 'Shop Now',
                'cta_destination' => $promo->cta_destination,
                'storefront_placement' => $promo->storefront_placement,
                'promotion_type' => $promo->promotion_type,
                'discount_type' => $promo->discount_type,
                'discount_value' => (float) $promo->discount_value,
                'formatted_discount' => $promo->formatted_discount,
                'min_order_amount' => (float) $promo->min_order_amount,
                'max_discount_amount' => $promo->max_discount_amount ? (float) $promo->max_discount_amount : null,
                'primary_code' => $promo->primary_code,
                'starts_at' => $promo->starts_at?->toISOString(),
                'expires_at' => $promo->expires_at?->toISOString(),
                'terms_conditions' => $promo->terms_conditions,
                'applies_to' => $promo->applies_to,
            ],
            'products' => $products,
        ]);
    }
}

