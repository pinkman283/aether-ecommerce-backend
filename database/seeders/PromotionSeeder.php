<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionClaim;
use App\Models\PromotionCode;
use App\Models\PromotionCustomerRestriction;
use App\Models\PromotionProductTarget;
use App\Models\User;
use App\Services\StoreCreditService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PromotionSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::whereIn('role', ['super_admin', 'admin'])->first();
        $adminId = $admin?->id;

        $tanvir = User::where('email', 'tanvir@test.com')->first();
        if (!$tanvir) {
            $tanvir = User::where('role', 'customer')->first();
        }

        // 1. Discount Code: AETHER10 (10% off entire order, min ৳1000)
        if (!Promotion::where('slug', 'aether10-welcome')->exists()) {
            $promo1 = Promotion::create([
                'name' => 'AETHER 10% Welcome Discount',
                'slug' => 'aether10-welcome',
                'description' => 'Get 10% off your entire cart on orders of ৳1,000 or more.',
                'promotion_type' => 'discount_code',
                'discount_type' => 'percentage',
                'discount_value' => 10.00,
                'max_discount_amount' => 1000.00,
                'applies_to' => 'entire_order',
                'status' => 'active',
                'min_order_amount' => 1000.00,
                'customer_eligibility' => 'all',
                'starts_at' => now()->subDays(5),
                'expires_at' => now()->addDays(60),
                'total_usage_limit' => 1000,
                'per_customer_usage_limit' => 2,
                'badge_text' => '10% OFF',
                'is_featured' => true,
                'created_by_user_id' => $adminId,
            ]);

            PromotionCode::create([
                'promotion_id' => $promo1->id,
                'code' => 'AETHER10',
                'usage_limit' => 1000,
                'is_active' => true,
            ]);
        }

        // 2. Discount Code: VIP500 (৳500 off orders over ৳3000)
        if (!Promotion::where('slug', 'vip500-elite')->exists()) {
            $promo2 = Promotion::create([
                'name' => 'VIP ৳500 Studio Credit Voucher',
                'slug' => 'vip500-elite',
                'description' => 'Flat ৳500 discount for orders above ৳3,000.',
                'promotion_type' => 'discount_code',
                'discount_type' => 'fixed_amount',
                'discount_value' => 500.00,
                'applies_to' => 'entire_order',
                'status' => 'active',
                'min_order_amount' => 3000.00,
                'customer_eligibility' => 'all',
                'starts_at' => now()->subDays(2),
                'expires_at' => now()->addDays(90),
                'total_usage_limit' => 500,
                'per_customer_usage_limit' => 1,
                'badge_text' => '৳500 OFF',
                'is_featured' => true,
                'created_by_user_id' => $adminId,
            ]);

            PromotionCode::create([
                'promotion_id' => $promo2->id,
                'code' => 'VIP500',
                'usage_limit' => 500,
                'is_active' => true,
            ]);
        }

        // 3. Claimable Coupon: CLAIM300 ("৳300 OFF Orders Over ৳2000", valid 7 days after claim)
        if (!Promotion::where('slug', 'claim300-hardware')->exists()) {
            $promo3 = Promotion::create([
                'name' => '৳300 Flash Hardware Voucher',
                'slug' => 'claim300-hardware',
                'description' => 'Claim this exclusive voucher to receive ৳300 off any purchase over ৳2,000. Valid for 7 days after claiming.',
                'promotion_type' => 'claimable_coupon',
                'discount_type' => 'fixed_amount',
                'discount_value' => 300.00,
                'applies_to' => 'entire_order',
                'status' => 'active',
                'min_order_amount' => 2000.00,
                'customer_eligibility' => 'all',
                'starts_at' => now()->subDay(),
                'expires_at' => now()->addDays(30),
                'claim_deadline' => now()->addDays(20),
                'claim_validity_days' => 7, // Key requirement: 7 days validity after claim!
                'total_claim_limit' => 500,
                'per_customer_usage_limit' => 1,
                'badge_text' => 'CLAIMABLE',
                'banner_image' => 'https://images.unsplash.com/photo-1550745165-9bc0b252726f?auto=format&fit=crop&w=1200&q=80',
                'thumbnail_image' => 'https://images.unsplash.com/photo-1550745165-9bc0b252726f?auto=format&fit=crop&w=400&q=80',
                'cta_text' => 'Claim Voucher',
                'cta_destination' => '/products',
                'is_featured' => true,
                'created_by_user_id' => $adminId,
            ]);

            PromotionCode::create([
                'promotion_id' => $promo3->id,
                'code' => 'CLAIM300',
                'usage_limit' => 500,
                'is_active' => true,
            ]);
        }

        // 4. Claimable Coupon: AUDIO50 (৳500 OFF Flagship Audio Category)
        $audioCategory = Category::where('slug', 'audio-acoustics')->first();
        if (!Promotion::where('slug', 'audio50-audiophile')->exists()) {
            $promo4 = Promotion::create([
                'name' => '৳500 Audiophile Acoustic Rebate',
                'slug' => 'audio50-audiophile',
                'description' => 'Claim ৳500 off any studio headphone, planar earphone, or monitor setup. Valid 14 days after claiming.',
                'promotion_type' => 'claimable_coupon',
                'discount_type' => 'product_fixed_discount',
                'discount_value' => 500.00,
                'applies_to' => 'specific_categories',
                'status' => 'active',
                'min_order_amount' => 1500.00,
                'customer_eligibility' => 'all',
                'starts_at' => now()->subDay(),
                'expires_at' => now()->addDays(45),
                'claim_deadline' => now()->addDays(30),
                'claim_validity_days' => 14,
                'total_claim_limit' => 200,
                'per_customer_usage_limit' => 1,
                'badge_text' => 'AUDIO EXCLUSIVE',
                'banner_image' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=1200&q=80',
                'thumbnail_image' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=400&q=80',
                'cta_text' => 'Claim Audio Pass',
                'cta_destination' => '/products?category=audio-acoustics',
                'is_featured' => true,
                'created_by_user_id' => $adminId,
            ]);

            PromotionCode::create([
                'promotion_id' => $promo4->id,
                'code' => 'AUDIO50',
                'usage_limit' => 200,
                'is_active' => true,
            ]);

            if ($audioCategory) {
                PromotionProductTarget::create([
                    'promotion_id' => $promo4->id,
                    'target_type' => 'category',
                    'target_id' => $audioCategory->id,
                ]);
            }
        }

        // 5. Automatic Discount: Free Express Shipping over ৳1,500 (No code required)
        if (!Promotion::where('slug', 'autoship-free')->exists()) {
            Promotion::create([
                'name' => 'Complimentary Express Courier Shipping',
                'slug' => 'autoship-free',
                'description' => 'Automatically unlocks 100% free delivery anywhere in Bangladesh when your cart reaches ৳1,500.',
                'promotion_type' => 'automatic_discount',
                'discount_type' => 'free_shipping',
                'discount_value' => 0.00,
                'applies_to' => 'shipping',
                'status' => 'active',
                'min_order_amount' => 1500.00,
                'customer_eligibility' => 'all',
                'starts_at' => now()->subDays(10),
                'expires_at' => now()->addDays(180),
                'is_stackable' => true,
                'can_combine_with_free_shipping' => true,
                'can_combine_with_order_discounts' => true,
                'can_combine_with_product_discounts' => true,
                'priority' => 10,
                'badge_text' => 'AUTO FREE SHIPPING',
                'is_featured' => false,
                'created_by_user_id' => $adminId,
            ]);
        }

        // 6. Buy 2 Get 1 Free (BXGY) on Keyboards
        $keyboardCategory = Category::where('slug', 'keyboards-desks')->first();
        if (!Promotion::where('slug', 'bxgy-keyboards')->exists()) {
            $promo6 = Promotion::create([
                'name' => 'Buy 2 Get 1 Free Keyboards & Desks',
                'slug' => 'bxgy-keyboards',
                'description' => 'Add any 3 custom mechanical keyboard items to your cart and the lowest-priced item is 100% free!',
                'promotion_type' => 'automatic_discount',
                'discount_type' => 'buy_x_get_y',
                'discount_value' => 0.00,
                'bxgy_buy_quantity' => 2,
                'bxgy_get_quantity' => 1,
                'bxgy_reward_discount_percent' => 100.00,
                'bxgy_max_applications' => 2,
                'applies_to' => 'specific_categories',
                'status' => 'active',
                'customer_eligibility' => 'all',
                'starts_at' => now()->subDays(5),
                'expires_at' => now()->addDays(90),
                'is_stackable' => true,
                'can_combine_with_free_shipping' => true,
                'priority' => 5,
                'badge_text' => 'BUY 2 GET 1 FREE',
                'is_featured' => true,
                'created_by_user_id' => $adminId,
            ]);

            if ($keyboardCategory) {
                PromotionProductTarget::create([
                    'promotion_id' => $promo6->id,
                    'target_type' => 'category',
                    'target_id' => $keyboardCategory->id,
                ]);
            }
        }

        // 7. Customer Reward / Next-Order Discount assigned to Tanvir Ahmed
        if ($tanvir && !Promotion::where('slug', 'reward-tanvir-20')->exists()) {
            $promo7 = Promotion::create([
                'name' => 'Next Order 20% Privilege Reward',
                'slug' => 'reward-tanvir-20',
                'description' => 'Customer appreciation discount: 20% off next order. Reason: Late delivery compensation.',
                'promotion_type' => 'next_order_discount',
                'discount_type' => 'percentage',
                'discount_value' => 20.00,
                'max_discount_amount' => 2000.00,
                'applies_to' => 'entire_order',
                'status' => 'active',
                'customer_eligibility' => 'next_order_only',
                'starts_at' => now(),
                'expires_at' => now()->addDays(7), // 7 days validity
                'total_usage_limit' => 1,
                'per_customer_usage_limit' => 1,
                'badge_text' => 'EXCLUSIVE REWARD',
                'created_by_user_id' => $adminId,
            ]);

            PromotionCode::create([
                'promotion_id' => $promo7->id,
                'code' => 'TANVIR20',
                'usage_limit' => 1,
                'is_active' => true,
            ]);

            PromotionCustomerRestriction::create([
                'promotion_id' => $promo7->id,
                'user_id' => $tanvir->id,
                'reason' => 'Late delivery compensation',
            ]);

            // Create initial claimed coupon for Tanvir so it appears in his dashboard
            PromotionClaim::create([
                'promotion_id' => $promo7->id,
                'user_id' => $tanvir->id,
                'claimed_code' => 'TANVIR20',
                'status' => 'claimed',
                'claimed_at' => now(),
                'expires_at' => now()->addDays(7),
            ]);

            // 8. Seed Store Credit for Tanvir Ahmed (+৳500)
            StoreCreditService::credit(
                $tanvir,
                500.00,
                'VIP Onboarding & Delivery Compensation Credit',
                'compensation',
                'Manual',
                null,
                $admin
            );
        }
    }
}
