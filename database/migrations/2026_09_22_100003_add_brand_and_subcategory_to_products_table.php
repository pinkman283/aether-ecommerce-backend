<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'brand_id')) {
                $table->foreignId('brand_id')->nullable()->after('category_id')->constrained('brands')->nullOnDelete();
            }
            if (!Schema::hasColumn('products', 'subcategory_id')) {
                $table->foreignId('subcategory_id')->nullable()->after('brand_id')->constrained('categories')->nullOnDelete();
            }

            // Index for fast catalog queries
            $table->index(['category_id', 'brand_id'], 'idx_products_cat_brand');
            $table->index(['subcategory_id', 'brand_id'], 'idx_products_subcat_brand');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_cat_brand');
            $table->dropIndex('idx_products_subcat_brand');
            $table->dropConstrainedForeignId('brand_id');
            $table->dropConstrainedForeignId('subcategory_id');
        });
    }
};
