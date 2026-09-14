<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'deleted_at')) {
                $table->softDeletes();
            }
            if (!Schema::hasColumn('orders', 'shipping_city_id')) {
                $table->unsignedBigInteger('shipping_city_id')->nullable()->after('shipping_address');
            }
            if (!Schema::hasColumn('orders', 'shipping_zone_id')) {
                $table->unsignedBigInteger('shipping_zone_id')->nullable()->after('shipping_city_id');
            }
            if (!Schema::hasColumn('orders', 'shipping_area_id')) {
                $table->unsignedBigInteger('shipping_area_id')->nullable()->after('shipping_zone_id');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['shipping_city_id', 'shipping_zone_id', 'shipping_area_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
