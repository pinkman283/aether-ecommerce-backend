<?php

namespace App\Console\Commands;

use App\Models\Promotion;
use App\Models\PromotionCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MigrateLegacyCoupons extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'promotions:migrate-legacy-coupons';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consolidates legacy coupons into the enterprise promotions and promotion_codes tables.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting migration of legacy coupons to promotions architecture...');

        if (!DB::getSchemaBuilder()->hasTable('coupons')) {
            $this->warn('Legacy coupons table does not exist. Nothing to migrate.');
            return Command::SUCCESS;
        }

        $legacyCoupons = DB::table('coupons')->get();
        $this->info("Found {$legacyCoupons->count()} legacy coupon record(s).");

        $migratedCount = 0;
        $skippedCount = 0;

        foreach ($legacyCoupons as $coupon) {
            $code = strtoupper(trim($coupon->code));

            // Check if code already exists in promotion_codes
            $existingCode = PromotionCode::where('code', $code)->first();
            if ($existingCode) {
                $this->line("Skipping code '{$code}': already exists in promotion_codes.");
                $skippedCount++;
                continue;
            }

            DB::transaction(function () use ($coupon, $code, &$migratedCount) {
                $discountType = $coupon->type === 'fixed' ? 'fixed_amount' : 'percentage';
                $slug = Str::slug('legacy-' . strtolower($code));

                // Check slug collision in promotions
                $uniqueSlug = $slug;
                $counter = 1;
                while (Promotion::where('slug', $uniqueSlug)->exists()) {
                    $uniqueSlug = "{$slug}-{$counter}";
                    $counter++;
                }

                $promotion = Promotion::create([
                    'name' => "Coupon: {$code}",
                    'slug' => $uniqueSlug,
                    'description' => "Migrated legacy coupon ({$code})",
                    'promotion_type' => 'discount_code',
                    'discount_type' => $discountType,
                    'discount_value' => (float) $coupon->value,
                    'applies_to' => 'entire_order',
                    'min_order_amount' => (float) $coupon->min_order_amount,
                    'max_discount_amount' => $coupon->max_discount_amount ? (float) $coupon->max_discount_amount : null,
                    'customer_eligibility' => 'all',
                    'status' => $coupon->is_active ? 'active' : 'inactive',
                    'total_usage_limit' => $coupon->usage_limit,
                    'total_used_count' => (int) $coupon->used_count,
                    'expires_at' => $coupon->expires_at,
                    'is_stackable' => false,
                    'can_combine_with_other_promotions' => false,
                    'can_combine_with_product_discounts' => false,
                    'can_combine_with_order_discounts' => false,
                    'can_combine_with_shipping_discounts' => false,
                    'priority' => 0,
                    'created_at' => $coupon->created_at ?? now(),
                    'updated_at' => $coupon->updated_at ?? now(),
                ]);

                PromotionCode::create([
                    'promotion_id' => $promotion->id,
                    'code' => $code,
                    'usage_limit' => $coupon->usage_limit,
                    'used_count' => (int) $coupon->used_count,
                    'is_active' => (bool) $coupon->is_active,
                    'created_at' => $coupon->created_at ?? now(),
                    'updated_at' => $coupon->updated_at ?? now(),
                ]);

                $migratedCount++;
            });
        }

        $this->info("Migration completed successfully! Migrated: {$migratedCount}, Skipped: {$skippedCount}.");
        return Command::SUCCESS;
    }
}
