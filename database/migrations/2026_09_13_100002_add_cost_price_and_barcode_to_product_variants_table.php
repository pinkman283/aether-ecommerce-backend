<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            if (!Schema::hasColumn('product_variants', 'cost_price')) {
                $table->decimal('cost_price', 12, 2)->nullable()->after('price_modifier');
            }
            if (!Schema::hasColumn('product_variants', 'barcode')) {
                $table->string('barcode', 100)->nullable()->index()->after('sku');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['cost_price', 'barcode']);
        });
    }
};
