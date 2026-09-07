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
        Schema::create('homepage_sections', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('subtitle')->nullable();
            $table->string('badge_text')->nullable();
            $table->string('badge_icon')->nullable()->default('Layers');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->integer('product_count')->default(14);
            $table->string('view_all_label')->default('View All');
            $table->string('view_all_url')->nullable();
            $table->boolean('has_tabs')->default(true);
            $table->json('tabs')->nullable();
            
            // Single product source configuration (used if has_tabs = false)
            $table->string('source_type')->default('category'); // 'category', 'brand', 'manual', 'dynamic'
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('sort_by')->default('featured'); // 'featured', 'newest', 'best_selling', 'price_asc', 'price_desc', 'rating'
            $table->json('product_ids')->nullable(); // Array of integer product IDs for manual selection

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('homepage_sections');
    }
};
