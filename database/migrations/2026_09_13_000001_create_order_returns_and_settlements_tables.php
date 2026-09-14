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
        // 1. Courier Settlements Header
        if (!Schema::hasTable('courier_settlements')) {
            Schema::create('courier_settlements', function (Blueprint $table) {
                $table->id();
                $table->string('settlement_number', 50)->unique();
                $table->string('provider', 50)->index(); // steadfast, pathao, redx
                $table->date('settlement_date')->index();
                $table->decimal('total_cod_collected', 14, 2)->default(0.00);
                $table->decimal('delivery_fees', 12, 2)->default(0.00);
                $table->decimal('return_fees', 12, 2)->default(0.00);
                $table->decimal('other_deductions', 12, 2)->default(0.00);
                $table->decimal('expected_payout', 14, 2)->default(0.00);
                $table->decimal('actual_payout', 14, 2)->default(0.00);
                $table->decimal('variance', 12, 2)->default(0.00);
                $table->unsignedBigInteger('bank_account_id')->nullable()->index();
                $table->unsignedBigInteger('journal_entry_id')->nullable()->index();
                $table->string('status', 50)->default('pending')->index(); // pending, reconciled, disputed
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('reconciled_by_user_id')->nullable()->index();
                $table->timestamp('reconciled_at')->nullable();
                $table->timestamps();

                $table->index(['provider', 'status']);
            });
        }

        // 2. Alter Shipments table for RTO charges and settlement linkage
        if (Schema::hasTable('shipments')) {
            Schema::table('shipments', function (Blueprint $table) {
                if (!Schema::hasColumn('shipments', 'rto_charge')) {
                    $table->decimal('rto_charge', 10, 2)->default(0.00)->after('courier_charge');
                }
                if (!Schema::hasColumn('shipments', 'collected_amount')) {
                    $table->decimal('collected_amount', 12, 2)->nullable()->after('rto_charge');
                }
                if (!Schema::hasColumn('shipments', 'remitted_amount')) {
                    $table->decimal('remitted_amount', 12, 2)->nullable()->after('collected_amount');
                }
                if (!Schema::hasColumn('shipments', 'settlement_status')) {
                    $table->string('settlement_status', 50)->default('unsettled')->index()->after('remitted_amount'); // unsettled, settled, disputed
                }
                if (!Schema::hasColumn('shipments', 'courier_settlement_id')) {
                    $table->unsignedBigInteger('courier_settlement_id')->nullable()->index()->after('settlement_status');
                }
            });
        }

        // 3. Courier Settlement Line Items (Shipment Reconciliation)
        if (!Schema::hasTable('courier_settlement_items')) {
            Schema::create('courier_settlement_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('courier_settlement_id')->constrained('courier_settlements')->cascadeOnDelete();
                $table->unsignedBigInteger('shipment_id')->nullable()->index();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->string('consignment_id', 100)->nullable()->index();
                $table->string('tracking_code', 100)->nullable()->index();
                $table->decimal('cod_collected', 12, 2)->default(0.00);
                $table->decimal('delivery_fee', 10, 2)->default(0.00);
                $table->decimal('rto_fee', 10, 2)->default(0.00);
                $table->decimal('cod_fee', 10, 2)->default(0.00);
                $table->decimal('other_fee', 10, 2)->default(0.00);
                $table->decimal('net_payout', 12, 2)->default(0.00);
                $table->string('status', 50)->default('matched')->index(); // matched, variance, unmatched
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        // 4. Alter Orders table to record separate courier collections, remittances, and return status
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (!Schema::hasColumn('orders', 'amount_collected_courier')) {
                    $table->decimal('amount_collected_courier', 12, 2)->default(0.00)->after('total_amount');
                }
                if (!Schema::hasColumn('orders', 'amount_remitted_merchant')) {
                    $table->decimal('amount_remitted_merchant', 12, 2)->default(0.00)->after('amount_collected_courier');
                }
                if (!Schema::hasColumn('orders', 'amount_refunded')) {
                    $table->decimal('amount_refunded', 12, 2)->default(0.00)->after('amount_remitted_merchant');
                }
                if (!Schema::hasColumn('orders', 'return_status')) {
                    $table->string('return_status', 50)->default('none')->index()->after('order_status'); // none, return_requested, rto_in_transit, received, qc_completed, resolved, cancelled
                }
            });
        }

        // 5. Order Returns Header
        if (!Schema::hasTable('order_returns')) {
            Schema::create('order_returns', function (Blueprint $table) {
                $table->id();
                $table->string('return_number', 50)->unique();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->unsignedBigInteger('shipment_id')->nullable()->index();
                $table->unsignedBigInteger('customer_id')->nullable()->index();
                
                // Classification & Status
                $table->string('return_type', 50)->default('customer_return')->index(); // rto, customer_return, failed_delivery, partial_rejection
                $table->string('status', 50)->default('initiated')->index(); // initiated, in_transit, received, qc_completed, resolved, rejected, cancelled
                $table->string('return_reason', 255)->nullable();
                
                // Financials Snapshot
                $table->decimal('order_total_snapshot', 12, 2)->default(0.00);
                $table->decimal('product_subtotal_snapshot', 12, 2)->default(0.00);
                $table->decimal('shipping_charge_snapshot', 10, 2)->default(0.00);
                $table->decimal('amount_collected_courier', 12, 2)->default(0.00);
                $table->decimal('amount_expected_from_customer', 12, 2)->default(0.00);
                $table->decimal('refund_amount', 12, 2)->default(0.00);
                $table->string('refund_method', 50)->default('none'); // cash, bank_transfer, mfs, store_credit, none
                $table->string('refund_status', 50)->default('none')->index(); // none, pending, processed, store_credit_issued, rejected
                
                // Logistics & Courier Costs
                $table->decimal('courier_delivery_fee', 10, 2)->default(0.00);
                $table->decimal('courier_rto_fee', 10, 2)->default(0.00);
                $table->string('courier_tracking_code', 100)->nullable();
                
                // Physical QC & Inspection Status
                $table->string('inspection_status', 50)->default('pending')->index(); // pending, passed, partial_damage, rejected
                $table->timestamp('received_at')->nullable();
                $table->timestamp('inspected_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                
                // Actors & Audit
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $table->unsignedBigInteger('inspected_by_user_id')->nullable()->index();
                $table->timestamps();

                $table->index(['order_id', 'status']);
                $table->index(['return_type', 'status']);
            });
        }

        // 6. Order Return Line Items
        if (!Schema::hasTable('order_return_items')) {
            Schema::create('order_return_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_return_id')->constrained('order_returns')->cascadeOnDelete();
                $table->unsignedBigInteger('order_item_id')->nullable()->index();
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->unsignedBigInteger('variant_id')->nullable()->index();
                
                $table->integer('quantity_returned')->default(1);
                $table->string('return_reason', 255)->nullable();
                $table->string('condition', 50)->default('unopened'); // unopened, opened_intact, damaged_packaging, damaged_product, defective, wrong_item
                $table->string('qc_status', 50)->default('pending')->index(); // pending, passed, damaged, defective
                $table->string('disposition', 50)->default('pending')->index(); // pending, restock_sellable, quarantine_damaged, write_off_loss, return_to_customer
                
                $table->integer('restocked_quantity')->default(0);
                $table->integer('damaged_quantity')->default(0);
                $table->integer('writeoff_quantity')->default(0);
                
                $table->decimal('unit_price', 10, 2)->default(0.00);
                $table->decimal('refund_unit_price', 10, 2)->default(0.00);
                $table->decimal('refund_subtotal', 10, 2)->default(0.00);
                $table->text('qc_notes')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_return_items');
        Schema::dropIfExists('order_returns');

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                $columns = ['amount_collected_courier', 'amount_remitted_merchant', 'amount_refunded', 'return_status'];
                foreach ($columns as $col) {
                    if (Schema::hasColumn('orders', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        Schema::dropIfExists('courier_settlement_items');

        if (Schema::hasTable('shipments')) {
            Schema::table('shipments', function (Blueprint $table) {
                $columns = ['rto_charge', 'collected_amount', 'remitted_amount', 'settlement_status', 'courier_settlement_id'];
                foreach ($columns as $col) {
                    if (Schema::hasColumn('shipments', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        Schema::dropIfExists('courier_settlements');
    }
};
