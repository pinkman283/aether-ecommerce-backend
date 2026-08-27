<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add fields to orders
        Schema::table('orders', function (Blueprint $table) {
            $table->string('order_source')->default('online')->after('order_number'); // online, pos, admin, draft
            $table->foreignId('pos_register_session_id')->nullable()->after('order_source')->constrained('pos_register_sessions')->nullOnDelete();
            $table->foreignId('cashier_user_id')->nullable()->after('pos_register_session_id')->constrained('users')->nullOnDelete();
            $table->decimal('cogs_amount', 12, 2)->default(0.00)->after('total_amount');
            $table->decimal('gross_profit', 12, 2)->default(0.00)->after('cogs_amount');
            $table->decimal('cash_received', 12, 2)->default(0.00)->after('gross_profit');
            $table->decimal('change_returned', 12, 2)->default(0.00)->after('cash_received');

            $table->index('order_source');
        });

        // 2. Add fields to order_items
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('cogs_unit_cost', 12, 2)->default(0.00)->after('unit_price');
            $table->decimal('cogs_total', 12, 2)->default(0.00)->after('cogs_unit_cost');
            $table->decimal('discount_amount', 12, 2)->default(0.00)->after('cogs_total');
            $table->decimal('gross_profit', 12, 2)->default(0.00)->after('total_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['cogs_unit_cost', 'cogs_total', 'discount_amount', 'gross_profit']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['pos_register_session_id']);
            $table->dropForeign(['cashier_user_id']);
            $table->dropColumn([
                'order_source',
                'pos_register_session_id',
                'cashier_user_id',
                'cogs_amount',
                'gross_profit',
                'cash_received',
                'change_returned',
            ]);
        });
    }
};
