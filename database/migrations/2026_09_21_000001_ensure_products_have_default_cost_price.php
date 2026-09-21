<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Populate standard default baseline cost_price (60% of retail price) for products without documented cost
        DB::statement("UPDATE products SET cost_price = ROUND(price * 0.60, 2) WHERE cost_price IS NULL OR cost_price <= 0;");
        
        // Populate variant cost_price if missing based on product price
        DB::statement("
            UPDATE product_variants pv 
            INNER JOIN products p ON pv.product_id = p.id 
            SET pv.cost_price = ROUND((p.price + COALESCE(pv.price_modifier, 0)) * 0.60, 2) 
            WHERE pv.cost_price IS NULL OR pv.cost_price <= 0;
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Non-destructive rollback
    }
};
