<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add cost_price to products
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'cost_price')) {
                $table->decimal('cost_price', 12, 2)->nullable()->after('price');
            }
        });

        // 2. Add composite lookup index to inventory_cost_layers
        Schema::table('inventory_cost_layers', function (Blueprint $table) {
            $table->index(['product_id', 'variant_id', 'is_depleted', 'remaining_quantity'], 'idx_fifo_sku_lookup');
        });

        // 3. Extend inventory_movements enum to support opening_balance
        DB::statement("ALTER TABLE inventory_movements MODIFY COLUMN movement_type ENUM('purchase_received', 'pos_sale', 'online_sale', 'customer_return', 'refund_restock', 'damage_writeoff', 'manual_adjustment', 'opening_balance') NOT NULL");
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'cost_price')) {
                $table->dropColumn('cost_price');
            }
        });

        Schema::table('inventory_cost_layers', function (Blueprint $table) {
            $table->dropIndex('idx_fifo_sku_lookup');
        });

        DB::statement("ALTER TABLE inventory_movements MODIFY COLUMN movement_type ENUM('purchase_received', 'pos_sale', 'online_sale', 'customer_return', 'refund_restock', 'damage_writeoff', 'manual_adjustment') NOT NULL");
    }
};
