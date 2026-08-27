<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Change payment_method and order_status to string on orders table for POS & refund flexibility
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method', 50)->default('cash_on_delivery')->change();
            $table->string('order_status', 50)->default('pending')->change();
            $table->string('payment_status', 50)->default('pending')->change();
        });
    }

    public function down(): void
    {
        // Revert to enums if necessary
    }
};
