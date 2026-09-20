<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->boolean('show_on_storefront')->default(false)->after('is_featured');
            $table->string('storefront_placement', 50)->nullable()->after('show_on_storefront'); // primary_hero, secondary_hero, top_strip, bottom_banner, flash_sale
            $table->string('headline', 255)->nullable()->after('storefront_placement');
            $table->string('subheadline', 255)->nullable()->after('headline');
            $table->string('image_alt_text', 255)->nullable()->after('subheadline');
            $table->text('mobile_banner_image')->nullable()->after('image_alt_text');
            $table->text('terms_conditions')->nullable()->after('mobile_banner_image');

            $table->index(['show_on_storefront', 'storefront_placement', 'status'], 'prom_storefront_idx');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropIndex('prom_storefront_idx');
            $table->dropColumn([
                'show_on_storefront',
                'storefront_placement',
                'headline',
                'subheadline',
                'image_alt_text',
                'mobile_banner_image',
                'terms_conditions',
            ]);
        });
    }
};
