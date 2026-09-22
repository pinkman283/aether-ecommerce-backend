<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navbar_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('navbar_items')->cascadeOnDelete();
            $table->string('title');
            $table->enum('type', ['category', 'subcategory', 'brand', 'custom', 'dropdown_group'])->default('category');
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('url')->nullable();
            $table->string('icon')->nullable();
            $table->string('badge')->nullable();
            $table->string('badge_color')->nullable();
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('open_in_new_tab')->default(false);
            $table->enum('mega_menu_type', ['none', 'category_brand_grid', 'columns', 'standard_dropdown'])->default('none');
            $table->timestamps();

            $table->index(['parent_id', 'display_order', 'is_active'], 'idx_nav_tree_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('navbar_items');
    }
};
