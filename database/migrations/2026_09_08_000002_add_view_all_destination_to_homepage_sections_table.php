<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('homepage_sections', function (Blueprint $table) {
            $table->string('view_all_type')->default('category')->after('view_all_label'); // 'category', 'brand', 'all_products', 'custom'
            $table->foreignId('view_all_category_id')->nullable()->after('view_all_type')->constrained('categories')->nullOnDelete();
            $table->foreignId('view_all_brand_id')->nullable()->after('view_all_category_id')->constrained('brands')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('homepage_sections', function (Blueprint $table) {
            $table->dropForeign(['view_all_category_id']);
            $table->dropForeign(['view_all_brand_id']);
            $table->dropColumn(['view_all_type', 'view_all_category_id', 'view_all_brand_id']);
        });
    }
};
