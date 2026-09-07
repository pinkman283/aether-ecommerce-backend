<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('image_url');
            $table->string('mobile_image_url')->nullable();
            $table->string('cta_text')->nullable()->default('Shop Now');
            $table->string('cta_link')->nullable()->default('/shop');
            $table->string('placement', 50)->default('hero_slider'); // hero_slider, top_announcement, middle_promo, popup, sidebar
            $table->string('badge', 50)->nullable();
            $table->string('discount_tag', 50)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->unsignedBigInteger('clicks_count')->default(0);
            $table->unsignedBigInteger('impressions_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
