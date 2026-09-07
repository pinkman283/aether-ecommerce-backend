<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\PromotionClaim;
use App\Models\PromotionCode;
use App\Models\PromotionCustomerRestriction;
use App\Models\PromotionProductTarget;
use App\Models\PromotionRedemption;
use App\Models\StoreCreditAccount;
use App\Models\StoreCreditTransaction;
use App\Models\User;
use App\Services\StoreCreditService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminPromotionController extends Controller
{
    /**
     * List all promotions with multi-parameter filtering and analytics summary cards.
     */
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'coupons.view', 'coupons.manage');

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

        $query = Promotion::with(['codes', 'productTargets'])->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%")
                  ->orWhereHas('codes', fn($c) => $c->where('code', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('promotion_type')) {
            $query->where('promotion_type', $request->input('promotion_type'));
        }

        if ($request->filled('discount_type')) {
            $query->where('discount_type', $request->input('discount_type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = (int) $request->input('per_page', 15);
        $promotions = $query->paginate($perPage);

        return response()->json($promotions);
    }

    /**
     * View single promotion details.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'coupons.view', 'coupons.manage');

        $promo = Promotion::with([
            'codes',
            'productTargets',
            'customerRestrictions.user:id,name,email',
            'createdBy:id,name',
        ])
        ->withCount(['claims', 'redemptions'])
        ->findOrFail($id);

        return response()->json([
            'promotion' => $promo,
            ...$promo->toArray(),
        ]);
    }

    /**
     * Create a new promotion using the complete 10-step configuration model.
     */
    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'coupons.manage');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:promotions,slug',
            'description' => 'nullable|string',
            'promotion_type' => 'required|in:discount_code,claimable_coupon,automatic_discount,customer_reward,next_order_discount',
            'discount_type' => 'required|in:percentage,fixed_amount,free_shipping,buy_x_get_y,product_fixed_discount,product_percentage_discount',
            'discount_value' => 'required|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'bxgy_buy_quantity' => 'nullable|integer|min:1',
            'bxgy_get_quantity' => 'nullable|integer|min:1',
            'bxgy_reward_discount_percent' => 'nullable|numeric|min:1|max:100',
            'bxgy_max_applications' => 'nullable|integer|min:1',
            'applies_to' => 'required|in:entire_order,specific_products,specific_categories,specific_brands,shipping',
            'status' => 'required|in:draft,scheduled,active,paused,expired,archived',
            'min_order_amount' => 'nullable|numeric|min:0',
            'max_order_amount' => 'nullable|numeric|min:0',
            'min_quantity' => 'nullable|integer|min:0',
            'max_quantity' => 'nullable|integer|min:0',
            'customer_eligibility' => 'required|in:all,specific_customers,new_customers,existing_customers,first_order_only,next_order_only',
            'payment_methods' => 'nullable|array',
            'shipping_methods' => 'nullable|array',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after_or_equal:starts_at',
            'claim_deadline' => 'nullable|date',
            'claim_validity_days' => 'nullable|integer|min:1',
            'total_usage_limit' => 'nullable|integer|min:1',
            'per_customer_usage_limit' => 'nullable|integer|min:1',
            'total_claim_limit' => 'nullable|integer|min:1',
            'is_stackable' => 'boolean',
            'can_combine_with_free_shipping' => 'boolean',
            'can_combine_with_order_discounts' => 'boolean',
            'can_combine_with_product_discounts' => 'boolean',
            'priority' => 'nullable|integer',
            'banner_image' => 'nullable|string',
            'thumbnail_image' => 'nullable|string',
            'badge_text' => 'nullable|string|max:50',
            'cta_text' => 'nullable|string|max:50',
            'cta_destination' => 'nullable|string|max:255',
            'is_featured' => 'boolean',

            // Codes (optional on creation)
            'code' => 'nullable|string|max:50',
            
            // Targets
            'product_ids' => 'nullable|array',
            'category_ids' => 'nullable|array',
            'brand_ids' => 'nullable|array',

            // Customer Restrictions
            'customer_ids' => 'nullable|array',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']) . '-' . Str::random(5);
        }

        $validated['created_by_user_id'] = $request->user()->id;

        $promotion = DB::transaction(function () use ($validated, $request) {
            $promo = Promotion::create($validated);

            // Handle primary promo code
            if (!empty($validated['code'])) {
                $cleanCode = strtoupper(trim($validated['code']));
                PromotionCode::create([
                    'promotion_id' => $promo->id,
                    'code' => $cleanCode,
                    'usage_limit' => $validated['total_usage_limit'] ?? null,
                    'is_active' => true,
                ]);
            }

            // Sync Product Targets
            if (!empty($validated['product_ids'])) {
                foreach ($validated['product_ids'] as $pid) {
                    PromotionProductTarget::create([
                        'promotion_id' => $promo->id,
                        'target_type' => 'product',
                        'target_id' => (int) $pid,
                    ]);
                }
            }
            if (!empty($validated['category_ids'])) {
                foreach ($validated['category_ids'] as $cid) {
                    PromotionProductTarget::create([
                        'promotion_id' => $promo->id,
                        'target_type' => 'category',
                        'target_id' => (int) $cid,
                    ]);
                }
            }
            if (!empty($validated['brand_ids'])) {
                foreach ($validated['brand_ids'] as $bid) {
                    PromotionProductTarget::create([
                        'promotion_id' => $promo->id,
                        'target_type' => 'brand',
                        'target_id' => (int) $bid,
                    ]);
                }
            }

            // Sync Customer Restrictions
            if (!empty($validated['customer_ids'])) {
                foreach ($validated['customer_ids'] as $uid) {
                    PromotionCustomerRestriction::create([
                        'promotion_id' => $promo->id,
                        'user_id' => (int) $uid,
                        'reason' => 'Designated customer eligibility',
                    ]);
                }
            }

            AuditLog::log(
                $request->user(),
                'promotion.created',
                'Promotion',
                $promo->id,
                "Created promotion '{$promo->name}' ({$promo->promotion_type})"
            );

            return $promo->load(['codes', 'productTargets', 'customerRestrictions']);
        });

        return response()->json([
            'message' => 'Promotion campaign created successfully',
            'promotion' => $promotion,
        ], 201);
    }

    /**
     * Update an existing promotion.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'coupons.manage');

        $promo = Promotion::findOrFail($id);
        $oldValues = $promo->toArray();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => "sometimes|required|string|max:255|unique:promotions,slug,{$id}",
            'description' => 'nullable|string',
            'promotion_type' => 'sometimes|required|in:discount_code,claimable_coupon,automatic_discount,customer_reward,next_order_discount',
            'discount_type' => 'sometimes|required|in:percentage,fixed_amount,free_shipping,buy_x_get_y,product_fixed_discount,product_percentage_discount',
            'discount_value' => 'sometimes|required|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'bxgy_buy_quantity' => 'nullable|integer|min:1',
            'bxgy_get_quantity' => 'nullable|integer|min:1',
            'bxgy_reward_discount_percent' => 'nullable|numeric|min:1|max:100',
            'bxgy_max_applications' => 'nullable|integer|min:1',
            'applies_to' => 'sometimes|required|in:entire_order,specific_products,specific_categories,specific_brands,shipping',
            'status' => 'sometimes|required|in:draft,scheduled,active,paused,expired,archived',
            'min_order_amount' => 'nullable|numeric|min:0',
            'max_order_amount' => 'nullable|numeric|min:0',
            'min_quantity' => 'nullable|integer|min:0',
            'max_quantity' => 'nullable|integer|min:0',
            'customer_eligibility' => 'sometimes|required|in:all,specific_customers,new_customers,existing_customers,first_order_only,next_order_only',
            'payment_methods' => 'nullable|array',
            'shipping_methods' => 'nullable|array',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date',
            'claim_deadline' => 'nullable|date',
            'claim_validity_days' => 'nullable|integer|min:1',
            'total_usage_limit' => 'nullable|integer|min:1',
            'per_customer_usage_limit' => 'nullable|integer|min:1',
            'total_claim_limit' => 'nullable|integer|min:1',
            'is_stackable' => 'boolean',
            'can_combine_with_free_shipping' => 'boolean',
            'can_combine_with_order_discounts' => 'boolean',
            'can_combine_with_product_discounts' => 'boolean',
            'priority' => 'nullable|integer',
            'banner_image' => 'nullable|string',
            'thumbnail_image' => 'nullable|string',
            'badge_text' => 'nullable|string|max:50',
            'cta_text' => 'nullable|string|max:50',
            'cta_destination' => 'nullable|string|max:255',
            'is_featured' => 'boolean',
        ]);

        $promo->fill($validated);
        $wasDirty = $promo->isDirty();
        $promo->save();

        if ($wasDirty) {
            AuditLog::log(
                $request->user(),
                'promotion.updated',
                'Promotion',
                $promo->id,
                "Updated promotion '{$promo->name}'",
                $oldValues,
                $promo->toArray()
            );
        }

        return response()->json([
            'message' => 'Promotion updated successfully',
            'promotion' => $promo->fresh(['codes', 'productTargets', 'customerRestrictions']),
        ]);
    }

    /**
     * Delete or archive a promotion.
     * Preserves historical integrity if redemptions exist.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'coupons.manage');

        $promo = Promotion::withCount('redemptions')->findOrFail($id);
        $name = $promo->name;

        if ($promo->redemptions_count > 0) {
            $promo->update(['status' => 'archived']);
            $msg = "Promotion '{$name}' has historical redemptions and was archived rather than deleted to preserve audit trails.";
        } else {
            $promo->delete();
            $msg = "Promotion '{$name}' deleted successfully.";
        }

        AuditLog::log(
            $request->user(),
            'promotion.deleted',
            'Promotion',
            $id,
            $msg
        );

        return response()->json(['message' => $msg]);
    }

    /**
     * Toggle promotion status (active / paused).
     */
    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'coupons.manage');

        $promo = Promotion::findOrFail($id);
        $newStatus = $promo->status === 'active' ? 'paused' : 'active';
        $promo->update(['status' => $newStatus]);

        return response()->json([
            'message' => "Promotion is now {$newStatus}.",
            'status' => $newStatus,
            'promotion' => $promo,
        ]);
    }

    /**
     * Generate unique codes in bulk for a promotion.
     */
    public function generateCodes(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'coupons.manage');

        $promo = Promotion::findOrFail($id);

        $validated = $request->validate([
            'count' => 'required|integer|min:1|max:500',
            'prefix' => 'nullable|string|max:10',
            'length' => 'nullable|integer|min:4|max:16',
            'usage_limit' => 'nullable|integer|min:1',
        ]);

        $count = $validated['count'];
        $prefix = strtoupper(trim($validated['prefix'] ?? ''));
        $length = $validated['length'] ?? 6;
        $usageLimit = $validated['usage_limit'] ?? 1;

        $created = [];
        for ($i = 0; $i < $count; $i++) {
            $uniquePart = strtoupper(Str::random($length));
            $codeStr = $prefix ? "{$prefix}-{$uniquePart}" : $uniquePart;

            // Ensure unique
            while (PromotionCode::where('code', $codeStr)->exists()) {
                $codeStr = ($prefix ? "{$prefix}-" : "") . strtoupper(Str::random($length));
            }

            $created[] = PromotionCode::create([
                'promotion_id' => $promo->id,
                'code' => $codeStr,
                'usage_limit' => $usageLimit,
                'is_active' => true,
            ]);
        }

        return response()->json([
            'message' => "Successfully generated {$count} promo codes for {$promo->name}.",
            'codes' => $created,
        ], 201);
    }

    /**
     * List all promotion voucher codes with search, promotion filter, and pagination.
     */
    public function codes(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'coupons.view', 'coupons.manage');

        $query = PromotionCode::with(['promotion:id,name,promotion_type,discount_type,discount_value,status'])->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhereHas('promotion', fn($p) => $p->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('promotion_id')) {
            $query->where('promotion_id', $request->input('promotion_id'));
        }

        if ($request->has('is_active') && $request->input('is_active') !== null && $request->input('is_active') !== '') {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = (int) $request->input('per_page', 20);
        return response()->json($query->paginate($perPage));
    }

    /**
     * View all coupon claims across promotions with filterability.
     */
    public function claims(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'coupons.view', 'coupons.manage');

        $query = PromotionClaim::with(['promotion:id,name,discount_type,discount_value', 'user:id,name,email', 'order:id,order_number'])->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('claimed_code', 'like', "%{$search}%")
                  ->orWhereHas('user', fn($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                  ->orWhereHas('promotion', fn($p) => $p->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json($query->paginate(20));
    }

    /**
     * View complete redemption history with customer, order number, and discount details.
     */
    public function redemptions(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'coupons.view', 'coupons.manage');

        $query = PromotionRedemption::with(['promotion:id,name', 'order:id,order_number', 'user:id,name,email'])->latest('created_at');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('customer_email', 'like', "%{$search}%")
                  ->orWhere('code_used', 'like', "%{$search}%")
                  ->orWhereHas('order', fn($o) => $o->where('order_number', 'like', "%{$search}%"))
                  ->orWhereHas('promotion', fn($p) => $p->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('promotion_type')) {
            $query->where('promotion_type', $request->input('promotion_type'));
        }

        return response()->json($query->paginate(20));
    }

    /**
     * Issue a direct Customer Reward / Next-Order discount to a specific customer.
     */
    public function issueCustomerReward(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'coupons.manage');

        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:users,id',
            'name' => 'required|string|max:255',
            'discount_type' => 'required|in:percentage,fixed_amount,free_shipping',
            'discount_value' => 'required|numeric|min:0.01',
            'min_order_amount' => 'nullable|numeric|min:0',
            'expires_in_days' => 'required|integer|min:1|max:365',
            'reason' => 'required|string|max:255', // e.g. "Late delivery compensation"
        ]);

        $user = User::findOrFail($validated['customer_id']);
        $uniqueCode = 'RWD-' . strtoupper(Str::random(6));

        $promotion = DB::transaction(function () use ($validated, $user, $uniqueCode, $request) {
            $promo = Promotion::create([
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']) . '-' . strtolower(Str::random(4)),
                'description' => "Private customer reward: {$validated['reason']}",
                'promotion_type' => 'customer_reward',
                'discount_type' => $validated['discount_type'],
                'discount_value' => $validated['discount_value'],
                'applies_to' => 'entire_order',
                'status' => 'active',
                'min_order_amount' => $validated['min_order_amount'] ?? 0.00,
                'customer_eligibility' => 'specific_customers',
                'starts_at' => now(),
                'expires_at' => now()->addDays($validated['expires_in_days']),
                'total_usage_limit' => 1,
                'per_customer_usage_limit' => 1,
                'badge_text' => 'VIP Reward',
                'created_by_user_id' => $request->user()->id,
            ]);

            PromotionCode::create([
                'promotion_id' => $promo->id,
                'code' => $uniqueCode,
                'usage_limit' => 1,
                'is_active' => true,
            ]);

            PromotionCustomerRestriction::create([
                'promotion_id' => $promo->id,
                'user_id' => $user->id,
                'reason' => $validated['reason'],
            ]);

            // Auto-create Claim for customer convenience
            PromotionClaim::create([
                'promotion_id' => $promo->id,
                'user_id' => $user->id,
                'claimed_code' => $uniqueCode,
                'status' => 'claimed',
                'claimed_at' => now(),
                'expires_at' => now()->addDays($validated['expires_in_days']),
            ]);

            AuditLog::log(
                $request->user(),
                'customer_reward.issued',
                'Promotion',
                $promo->id,
                "Issued reward '{$promo->name}' ({$uniqueCode}) to customer {$user->name} ({$user->email}). Reason: {$validated['reason']}"
            );

            return $promo->load(['codes', 'customerRestrictions']);
        });

        return response()->json([
            'message' => "Customer reward successfully issued to {$user->name} with code {$uniqueCode}.",
            'promotion' => $promotion,
            'code' => $uniqueCode,
        ], 201);
    }

    /**
     * List all customer store credit accounts.
     */
    public function storeCreditAccounts(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'coupons.view', 'coupons.manage');

        $query = StoreCreditAccount::with('user:id,name,email,phone')->latest('updated_at');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('user', function ($u) use ($search) {
                $u->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate(20));
    }

    /**
     * Perform an auditable manual credit or debit adjustment to a customer's store credit wallet.
     */
    public function adjustStoreCredit(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'coupons.manage');

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'type' => 'required|in:credit,debit,adjustment,compensation,reward',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
        ]);

        $customer = User::findOrFail($validated['user_id']);
        $amount = (float) $validated['amount'];
        $staff = $request->user();

        if (in_array($validated['type'], ['debit'])) {
            $tx = StoreCreditService::debit($customer, $amount, $validated['reason'], 'Manual', null, $staff);
        } else {
            $tx = StoreCreditService::credit($customer, $amount, $validated['reason'], $validated['type'], 'Manual', null, $staff);
        }

        AuditLog::log(
            $staff,
            'store_credit.adjusted',
            'StoreCreditAccount',
            $tx->account_id,
            "{$validated['type']} of \${$amount} applied to {$customer->name} ({$customer->email}). Reason: {$validated['reason']}"
        );

        return response()->json([
            'message' => "Store credit {$validated['type']} of \${$amount} successfully applied.",
            'transaction' => $tx->load('account'),
        ]);
    }

    /**
     * Get transaction ledger across all customers or for a specific customer.
     */
    public function storeCreditLedger(Request $request, ?int $userId = null): JsonResponse
    {
        $this->checkPermission($request, 'coupons.view', 'coupons.manage');

        $query = StoreCreditTransaction::with(['user:id,name,email,phone', 'createdBy:id,name', 'order:id,order_number'])->latest();

        $targetUserId = $userId ?: $request->input('user_id');
        if ($targetUserId) {
            $query->where('user_id', (int) $targetUserId);
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('reason', 'like', "%{$search}%")
                  ->orWhere('type', 'like', "%{$search}%")
                  ->orWhereHas('user', fn($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $perPage = (int) $request->input('per_page', 25);
        return response()->json($query->paginate($perPage));
    }

    /**
     * Promotion Analytics Dashboard:
     * KPI totals, redemptions, revenue generated, claim conversion, and top campaigns.
     */
    public function analytics(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'coupons.view', 'coupons.manage');

        $totalRedemptions = PromotionRedemption::count();
        $totalDiscountVolume = (float) PromotionRedemption::sum('discount_amount');
        $totalRevenueWithPromos = (float) PromotionRedemption::sum('order_total');

        $totalClaims = PromotionClaim::count();
        $redeemedClaims = PromotionClaim::where('status', 'redeemed')->count();
        $expiredClaims = PromotionClaim::where('status', 'expired')->orWhere(function ($q) {
            $q->where('status', 'claimed')->where('expires_at', '<', now());
        })->count();

        $claimConversionRate = $totalClaims > 0 ? round(($redeemedClaims / $totalClaims) * 100, 1) : 0.0;

        $storeCreditIssued = (float) StoreCreditTransaction::whereIn('type', ['credit', 'reward', 'compensation'])->sum('amount');
        $storeCreditRedeemed = (float) StoreCreditTransaction::where('type', 'debit')->sum('amount');
        $activeStoreCreditBalance = (float) StoreCreditAccount::sum('balance');

        // Top 5 Performing Promotions
        $topPromotions = Promotion::withCount('redemptions')
            ->having('redemptions_count', '>', 0)
            ->orderByDesc('redemptions_count')
            ->limit(5)
            ->get(['id', 'name', 'promotion_type', 'discount_type', 'discount_value', 'total_used_count']);

        // Recent 30 Days Redemptions Trend
        $recentTrend = PromotionRedemption::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(discount_amount) as discount_sum'),
                DB::raw('SUM(order_total) as revenue_sum')
            )
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        return response()->json([
            'kpis' => [
                'total_redemptions' => $totalRedemptions,
                'total_discount_volume' => $totalDiscountVolume,
                'total_revenue_with_promos' => $totalRevenueWithPromos,
                'total_claims' => $totalClaims,
                'redeemed_claims' => $redeemedClaims,
                'expired_claims' => $expiredClaims,
                'claim_conversion_rate' => $claimConversionRate,
                'store_credit_issued' => $storeCreditIssued,
                'store_credit_redeemed' => $storeCreditRedeemed,
                'active_store_credit_balance' => $activeStoreCreditBalance,
            ],
            'top_promotions' => $topPromotions,
            'recent_trend' => $recentTrend,
        ]);
    }
}
