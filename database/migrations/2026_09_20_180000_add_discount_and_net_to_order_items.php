<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'discount_amount')) {
                $table->decimal('discount_amount', 10, 2)->default(0.00)->after('total_price');
            }
            if (!Schema::hasColumn('order_items', 'tax_amount')) {
                $table->decimal('tax_amount', 10, 2)->default(0.00)->after('discount_amount');
            }
            if (!Schema::hasColumn('order_items', 'net_total')) {
                $table->decimal('net_total', 10, 2)->default(0.00)->after('tax_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['discount_amount', 'tax_amount', 'net_total']);
        });
    }
};
