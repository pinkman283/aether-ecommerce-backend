<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_brand', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->integer('display_order')->default(0);
            $table->boolean('is_in_navbar')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();

            $table->unique(['category_id', 'brand_id'], 'uq_cat_brand');
            $table->index(['category_id', 'display_order'], 'idx_cat_brand_order');
            $table->index(['brand_id'], 'idx_brand_pivot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_brand');
    }
};
