<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. POS Registers
        Schema::create('pos_registers', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g. Register #1 - Main Terminal
            $table->string('code')->unique(); // e.g. REG-01
            $table->enum('status', ['open', 'closed'])->default('closed');
            $table->timestamps();
        });

        // 2. POS Register Sessions
        Schema::create('pos_register_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_register_id')->constrained('pos_registers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // Cashier
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_balance', 12, 2)->default(0.00);
            $table->decimal('cash_sales_amount', 12, 2)->default(0.00);
            $table->decimal('card_sales_amount', 12, 2)->default(0.00);
            $table->decimal('mobile_sales_amount', 12, 2)->default(0.00);
            $table->decimal('cash_in_amount', 12, 2)->default(0.00);
            $table->decimal('cash_out_amount', 12, 2)->default(0.00);
            $table->decimal('cash_refunds_amount', 12, 2)->default(0.00);
            $table->decimal('expected_cash_balance', 12, 2)->default(0.00);
            $table->decimal('actual_closing_cash', 12, 2)->nullable();
            $table->decimal('cash_difference', 12, 2)->nullable();
            $table->text('closing_notes')->nullable();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamps();

            $table->index('status');
            $table->index('user_id');
        });

        // 3. POS Cash Movements (Cash In / Cash Out / Safe Drops)
        Schema::create('pos_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_register_session_id')->constrained('pos_register_sessions')->cascadeOnDelete();
            $table->enum('type', ['cash_in', 'cash_out', 'drop']);
            $table->decimal('amount', 12, 2);
            $table->string('reason');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // 4. Expense Categories
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 5. Operating Expenses
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('expense_number')->unique(); // e.g. EXP-2026-0001
            $table->foreignId('expense_category_id')->constrained('expense_categories')->cascadeOnDelete();
            $table->string('title');
            $table->decimal('amount', 12, 2);
            $table->date('expense_date');
            $table->foreignId('payee_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('payee_name')->nullable();
            $table->string('payment_method')->default('cash'); // cash, bank_transfer, credit_card, check, mobile_money
            $table->string('reference_number')->nullable();
            $table->text('receipt_attachment_url')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['recorded', 'approved', 'cancelled'])->default('recorded');
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('expense_date');
            $table->index('status');
            $table->index('expense_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('pos_cash_movements');
        Schema::dropIfExists('pos_register_sessions');
        Schema::dropIfExists('pos_registers');
    }
};
