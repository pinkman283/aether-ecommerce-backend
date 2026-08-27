<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Vendors
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('vendor_code')->unique(); // e.g. VND-001
            $table->string('name');
            $table->string('company_name');
            $table->string('contact_person')->nullable();
            $table->string('phone');
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('payment_terms')->default('net_30'); // net_15, net_30, cod, due_on_receipt
            $table->text('notes')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index('status');
            $table->index('name');
        });

        // 2. Vendor Products (Supplier relationship and pricing)
        Schema::create('vendor_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('vendor_sku')->nullable();
            $table->decimal('purchase_price', 12, 2);
            $table->string('currency', 10)->default('USD');
            $table->integer('min_order_quantity')->default(1);
            $table->integer('lead_time_days')->default(7);
            $table->boolean('is_primary')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['vendor_id', 'product_id', 'variant_id'], 'vendor_product_unique');
        });

        // 3. Vendor Price History
        Schema::create('vendor_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('price', 12, 2);
            $table->date('effective_date');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'product_id']);
        });

        // 4. Purchase Orders
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number')->unique(); // e.g. PO-2026-0001
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->enum('status', ['draft', 'submitted', 'approved', 'partially_received', 'received', 'cancelled'])->default('draft');
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0.00);
            $table->decimal('shipping_cost', 12, 2)->default(0.00);
            $table->decimal('tax_amount', 12, 2)->default(0.00);
            $table->decimal('other_costs', 12, 2)->default(0.00);
            $table->decimal('total_amount', 12, 2)->default(0.00);
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('order_date');
        });

        // 5. Purchase Order Items
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('product_name');
            $table->string('sku');
            $table->decimal('unit_cost', 12, 2);
            $table->integer('quantity_ordered');
            $table->integer('quantity_received')->default(0);
            $table->integer('quantity_damaged')->default(0);
            $table->integer('quantity_rejected')->default(0);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();
        });

        // 6. Goods Receipts (GRN)
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number')->unique(); // e.g. GRN-2026-0001
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('received_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->date('received_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('received_date');
        });

        // 7. Goods Receipt Items
        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->integer('quantity_received');
            $table->integer('quantity_damaged')->default(0);
            $table->integer('quantity_rejected')->default(0);
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('total_cost', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('vendor_price_history');
        Schema::dropIfExists('vendor_products');
        Schema::dropIfExists('vendors');
    }
};
