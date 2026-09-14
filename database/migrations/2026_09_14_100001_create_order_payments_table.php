<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('payment_number', 50)->unique();
            $table->string('payment_method', 50)->default('cash_on_delivery');
            $table->string('provider', 50)->default('manual');
            $table->string('transaction_id', 100)->nullable()->index();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 10)->default('BDT');
            $table->string('status', 30)->default('pending')->index(); // pending, completed, failed, refunded, partially_refunded
            $table->string('type', 30)->default('collection')->index(); // collection, partial_payment, full_payment, refund, advance
            $table->timestamp('collected_at')->nullable()->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
