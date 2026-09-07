<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Core Promotions Table
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            
            // Classification
            $table->enum('promotion_type', [
                'discount_code',
                'claimable_coupon',
                'automatic_discount',
                'customer_reward',
                'next_order_discount'
            ])->default('discount_code');

            $table->enum('discount_type', [
                'percentage',
                'fixed_amount',
                'free_shipping',
                'buy_x_get_y',
                'product_fixed_discount',
                'product_percentage_discount'
            ])->default('percentage');

            $table->decimal('discount_value', 10, 2)->default(0.00);
            $table->decimal('max_discount_amount', 10, 2)->nullable();

            // Buy X Get Y (BXGY) Config
            $table->unsignedInteger('bxgy_buy_quantity')->nullable();
            $table->unsignedInteger('bxgy_get_quantity')->nullable();
            $table->decimal('bxgy_reward_discount_percent', 5, 2)->default(100.00); // 100% = free, 50% = 50% off
            $table->unsignedInteger('bxgy_max_applications')->nullable();

            // Targeting scope
            $table->enum('applies_to', [
                'entire_order',
                'specific_products',
                'specific_categories',
                'specific_brands',
                'shipping'
            ])->default('entire_order');

            // Lifecycle status
            $table->enum('status', [
                'draft',
                'scheduled',
                'active',
                'paused',
                'expired',
                'archived'
            ])->default('active');

            // Conditions
            $table->decimal('min_order_amount', 10, 2)->default(0.00);
            $table->decimal('max_order_amount', 10, 2)->nullable();
            $table->unsignedInteger('min_quantity')->nullable();
            $table->unsignedInteger('max_quantity')->nullable();
            
            $table->enum('customer_eligibility', [
                'all',
                'specific_customers',
                'new_customers',
                'existing_customers',
                'first_order_only',
                'next_order_only'
            ])->default('all');

            $table->json('payment_methods')->nullable();
            $table->json('shipping_methods')->nullable();

            // Scheduling & Expiration
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('claim_deadline')->nullable();
            $table->unsignedInteger('claim_validity_days')->nullable(); // Coupon valid for N days after claim

            // Limits
            $table->unsignedInteger('total_usage_limit')->nullable();
            $table->unsignedInteger('total_used_count')->default(0);
            $table->unsignedInteger('per_customer_usage_limit')->default(1);
            $table->unsignedInteger('total_claim_limit')->nullable();
            $table->unsignedInteger('total_claimed_count')->default(0);

            // Stacking & Priority
            $table->boolean('is_stackable')->default(false);
            $table->boolean('can_combine_with_free_shipping')->default(false);
            $table->boolean('can_combine_with_order_discounts')->default(false);
            $table->boolean('can_combine_with_product_discounts')->default(false);
            $table->integer('priority')->default(0);

            // Marketing & Presentation
            $table->text('banner_image')->nullable();
            $table->text('thumbnail_image')->nullable();
            $table->string('badge_text')->nullable();
            $table->string('cta_text')->nullable();
            $table->string('cta_destination')->nullable();
            $table->boolean('is_featured')->default(false);

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['promotion_type', 'status']);
            $table->index(['starts_at', 'expires_at']);
        });

        // 2. Promotion Codes (supports multiple codes or generated codes per promotion)
        Schema::create('promotion_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->string('code')->unique();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('code');
        });

        // 3. Promotion Product / Category / Brand Targets
        Schema::create('promotion_product_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->enum('target_type', ['product', 'category', 'brand']);
            $table->unsignedBigInteger('target_id');
            $table->boolean('is_exclusion')->default(false);
            $table->timestamps();

            $table->index(['promotion_id', 'target_type', 'target_id'], 'prom_targets_idx');
        });

        // 4. Customer-Specific Restrictions / Direct Customer Rewards
        Schema::create('promotion_customer_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason')->nullable();
            $table->boolean('is_used')->default(false);
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->unique(['promotion_id', 'user_id'], 'prom_cust_unique');
        });

        // 5. Promotion Claims (Customer Claimable Coupons Lifecycle)
        Schema::create('promotion_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('claimed_code');
            $table->enum('status', ['claimed', 'redeemed', 'expired'])->default('claimed');
            $table->timestamp('claimed_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['promotion_id', 'user_id']);
        });

        // 6. Promotion Redemptions (Immutable Audit Ledger)
        Schema::create('promotion_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            $table->foreignId('promotion_code_id')->nullable()->constrained('promotion_codes')->nullOnDelete();
            $table->foreignId('promotion_claim_id')->nullable()->constrained('promotion_claims')->nullOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('customer_email');
            $table->string('code_used')->nullable();
            $table->string('promotion_type');
            $table->string('discount_type');
            $table->decimal('discount_amount', 10, 2);
            $table->decimal('order_subtotal', 10, 2);
            $table->decimal('order_total', 10, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['promotion_id', 'user_id']);
            $table->index(['customer_email']);
            $table->index(['order_id']);
        });

        // 7. Store Credit Accounts (Customer Wallets)
        Schema::create('store_credit_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->decimal('balance', 12, 2)->default(0.00);
            $table->decimal('total_credited', 12, 2)->default(0.00);
            $table->decimal('total_debited', 12, 2)->default(0.00);
            $table->boolean('is_frozen')->default(false);
            $table->timestamps();
        });

        // 8. Store Credit Transactions (Auditable Ledger)
        Schema::create('store_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('store_credit_accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['credit', 'debit', 'adjustment', 'refund', 'reward', 'compensation']);
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('reason');
            $table->string('reference_type')->nullable(); // Order, Promotion, Manual
            $table->string('reference_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'created_at']);
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_credit_transactions');
        Schema::dropIfExists('store_credit_accounts');
        Schema::dropIfExists('promotion_redemptions');
        Schema::dropIfExists('promotion_claims');
        Schema::dropIfExists('promotion_customer_restrictions');
        Schema::dropIfExists('promotion_product_targets');
        Schema::dropIfExists('promotion_codes');
        Schema::dropIfExists('promotions');
    }
};
