<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Inventory Cost Layers (FIFO Tranches)
        Schema::create('inventory_cost_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('goods_receipt_item_id')->nullable()->constrained('goods_receipt_items')->nullOnDelete();
            $table->decimal('unit_cost', 12, 2);
            $table->integer('initial_quantity');
            $table->integer('remaining_quantity');
            $table->boolean('is_depleted')->default(false);
            $table->timestamps();

            $table->index(['product_id', 'is_depleted']);
            $table->index(['variant_id', 'is_depleted']);
            $table->index('created_at');
        });

        // 2. Inventory Movements (Auditable Transaction Ledger)
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->enum('movement_type', [
                'purchase_received',
                'pos_sale',
                'online_sale',
                'customer_return',
                'refund_restock',
                'damage_writeoff',
                'manual_adjustment'
            ]);
            $table->integer('quantity'); // Positive for inbound, negative for outbound
            $table->decimal('unit_cost', 12, 2)->default(0.00);
            $table->decimal('total_cost', 12, 2)->default(0.00);
            $table->integer('balance_after');
            $table->string('reference_type')->nullable(); // Order, GoodsReceipt, Adjustment, etc.
            $table->string('reference_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
            $table->index('movement_type');
            $table->index('reference_type');
        });

        // 3. Order Item Cost Layers (Exact FIFO Traceability per sold item)
        Schema::create('order_item_cost_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('inventory_cost_layer_id')->constrained('inventory_cost_layers')->cascadeOnDelete();
            $table->integer('quantity_consumed');
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('total_cost', 12, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->index('order_item_id');
            $table->index('inventory_cost_layer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_cost_layers');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_cost_layers');
    }
};
