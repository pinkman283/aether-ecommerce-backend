<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('rating_average', 3, 2)->default(0.00)->change();
        });

        DB::table('products')->where('review_count', 0)->update(['rating_average' => 0.00]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('rating_average', 3, 2)->default(5.00)->change();
        });
    }
};
