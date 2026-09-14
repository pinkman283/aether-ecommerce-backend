<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;

echo "Starting cleanup...\n";

Schema::dropIfExists('order_return_items');
Schema::dropIfExists('order_returns');
Schema::dropIfExists('courier_settlement_items');

if (Schema::hasTable('shipments')) {
    Schema::table('shipments', function ($table) {
        if (Schema::hasColumn('shipments', 'courier_settlement_id')) {
            try {
                $table->dropForeign(['courier_settlement_id']);
            } catch (\Throwable $e) {}
            $table->dropColumn('courier_settlement_id');
        }
        foreach (['rto_charge', 'collected_amount', 'remitted_amount', 'settlement_status'] as $col) {
            if (Schema::hasColumn('shipments', $col)) {
                $table->dropColumn($col);
            }
        }
    });
}

if (Schema::hasTable('orders')) {
    Schema::table('orders', function ($table) {
        foreach (['amount_collected_courier', 'amount_remitted_merchant', 'amount_refunded', 'return_status'] as $col) {
            if (Schema::hasColumn('orders', $col)) {
                $table->dropColumn($col);
            }
        }
    });
}

Schema::dropIfExists('courier_settlements');

echo "Cleanup complete.\n";
