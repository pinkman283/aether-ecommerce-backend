<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'store_credit_amount')) {
                $table->decimal('store_credit_amount', 10, 2)->default(0.00)->after('discount_amount');
            }
            if (!Schema::hasColumn('orders', 'promotion_id')) {
                $table->unsignedBigInteger('promotion_id')->nullable()->after('coupon_code');
            }
            if (!Schema::hasColumn('orders', 'promotion_discount_details')) {
                $table->json('promotion_discount_details')->nullable()->after('promotion_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['store_credit_amount', 'promotion_id', 'promotion_discount_details']);
        });
    }
};
