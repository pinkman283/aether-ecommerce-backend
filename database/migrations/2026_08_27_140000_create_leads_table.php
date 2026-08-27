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
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->json('cart_items')->nullable();
            $table->decimal('total_amount', 10, 2)->default(0.00);
            $table->enum('status', ['new', 'contacted', 'in_progress', 'converted', 'lost'])->default('new');
            $table->text('notes')->nullable();
            $table->string('source')->default('checkout_abandonment');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('converted_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('phone');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
