<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('original_unit_price', 14, 2)->nullable()->after('unit_price');
            $table->boolean('is_price_overridden')->default(false)->after('original_unit_price');
            $table->string('override_reason', 255)->nullable()->after('is_price_overridden');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['original_unit_price', 'is_price_overridden', 'override_reason']);
        });
    }
};
