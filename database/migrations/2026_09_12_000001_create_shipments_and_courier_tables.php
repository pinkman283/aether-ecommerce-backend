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
        // 1. Create shipments table
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('provider', 50)->index(); // steadfast, pathao, redx
            $table->string('consignment_id', 100)->nullable()->index();
            $table->string('tracking_code', 100)->nullable()->index();
            $table->string('tracking_url', 500)->nullable();
            $table->string('status', 50)->default('draft')->index();
            $table->string('courier_status_raw', 100)->nullable();

            // Recipient snapshot for logistics dispatch
            $table->string('recipient_name');
            $table->string('recipient_phone', 30);
            $table->text('recipient_address');

            // Financials & Parcel Specs
            $table->decimal('cod_amount', 12, 2)->default(0.00);
            $table->decimal('courier_charge', 10, 2)->default(0.00);
            $table->decimal('courier_cod_fee', 10, 2)->default(0.00);
            $table->decimal('weight', 8, 2)->default(0.50); // in kg
            $table->string('delivery_area', 150)->nullable();
            $table->string('pickup_store_id', 100)->nullable();
            $table->text('notes')->nullable();

            // Lifecycle metrics & issues
            $table->unsignedSmallInteger('delivery_attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->text('return_reason')->nullable();

            // Operational timestamps
            $table->timestamp('booked_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('in_transit_at')->nullable();
            $table->timestamp('out_for_delivery_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            // Raw payloads
            $table->json('raw_response')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['provider', 'status']);
            $table->index(['provider', 'consignment_id']);
        });

        // 2. Create courier_webhook_logs table
        Schema::create('courier_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50)->index();
            $table->string('event_type', 100)->nullable();
            $table->string('consignment_id', 100)->nullable()->index();
            $table->string('tracking_code', 100)->nullable()->index();
            $table->json('payload');
            $table->json('headers')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('status', 50)->default('processed')->index(); // processed, ignored, failed, duplicate
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        // 3. Add shipping_method to orders table if not present
        if (!Schema::hasColumn('orders', 'shipping_method')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('shipping_method', 50)->nullable()->after('shipping_amount');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('orders', 'shipping_method')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('shipping_method');
            });
        }
        Schema::dropIfExists('courier_webhook_logs');
        Schema::dropIfExists('shipments');
    }
};
