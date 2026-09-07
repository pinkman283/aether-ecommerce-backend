<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->string('eyebrow', 100)->nullable()->after('subtitle');
            $table->string('alt_text', 255)->nullable()->after('mobile_image_url');
            $table->string('destination_type', 50)->nullable()->default('custom')->after('cta_link'); // product, category, brand, collection, promotion, page, custom
            $table->unsignedBigInteger('destination_id')->nullable()->after('destination_type');
            $table->foreignId('promotion_id')->nullable()->after('destination_id')->constrained('promotions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropForeign(['promotion_id']);
            $table->dropColumn([
                'eyebrow',
                'alt_text',
                'destination_type',
                'destination_id',
                'promotion_id',
            ]);
        });
    }
};
