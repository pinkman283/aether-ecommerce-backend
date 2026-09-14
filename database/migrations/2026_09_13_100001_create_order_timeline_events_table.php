<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_timeline_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('event_type', 60)->index(); // order_placed, confirmed, courier_booked, picked_up, out_for_delivery, delivered, customer_refused, rto_initiated, returned_to_warehouse, qc_completed, restocked, accounting_adjusted, cancelled, refunded
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('actor_name', 100)->nullable();
            $table->string('icon_type', 30)->default('check'); // check, truck, alert, rotate, dollar, shield, x
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_timeline_events');
    }
};
