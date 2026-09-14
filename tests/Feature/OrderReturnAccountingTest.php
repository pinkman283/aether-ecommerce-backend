<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\Shipment;
use App\Services\AccountingService;
use App\Services\OrderReturnService;
use Tests\TestCase;

class OrderReturnAccountingTest extends TestCase
{
    public function test_zero_refund_rto_posts_balanced_journal_entry(): void
    {
        // 1. Ensure required accounts exist in test DB
        ChartOfAccount::firstOrCreate(['account_code' => '1100'], ['account_name' => 'Accounts Receivable', 'account_type' => 'asset']);
        ChartOfAccount::firstOrCreate(['account_code' => '4095'], ['account_name' => 'Sales Returns & Allowances', 'account_type' => 'equity']);
        ChartOfAccount::firstOrCreate(['account_code' => '4030'], ['account_name' => 'Shipping Income / Expense', 'account_type' => 'expense']);

        // 2. Create sample product & order
        $product = Product::create([
            'name' => 'Mechanical Keyboard',
            'slug' => 'mech-key-' . uniqid(),
            'sku' => 'KB-' . uniqid(),
            'price' => 1000.00,
            'stock_quantity' => 10,
            'description' => 'Test product',
            'category_id' => 1,
        ]);

        $order = Order::create([
            'order_number' => 'ORD-RTO-' . strtoupper(uniqid()),
            'customer_name' => 'Rahim Ahmed',
            'customer_phone' => '01711000000',
            'customer_email' => 'rahim@example.com',
            'shipping_address' => ['city' => 'Dhaka'],
            'subtotal' => 1000.00,
            'shipping_amount' => 100.00,
            'tax_amount' => 0.00,
            'discount_amount' => 0.00,
            'total_amount' => 1100.00,
            'payment_status' => 'pending',
            'payment_method' => 'cash_on_delivery',
            'order_status' => 'shipped',
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'unit_price' => 1000.00,
            'quantity' => 1,
            'total_price' => 1000.00,
        ]);

        $shipment = Shipment::create([
            'order_id' => $order->id,
            'provider' => 'steadfast',
            'consignment_id' => 'ST-TEST-' . uniqid(),
            'tracking_code' => 'TRK-' . uniqid(),
            'status' => 'booked',
            'recipient_name' => 'Rahim Ahmed',
            'recipient_phone' => '01711000000',
            'recipient_address' => 'Dhaka',
            'cod_amount' => 1100.00,
        ]);

        // 3. Process RTO Return with ZERO refund
        $orderReturn = app(OrderReturnService::class)->handleCourierRtoEvent($shipment, [
            'rto_charge' => 60.00,
            'collected_amount' => 0.00,
            'return_reason' => 'Customer Refused on Delivery',
        ]);

        $this->assertNotNull($orderReturn);

        // Finalize RTO zero-collection accounting adjustment
        $orderReturn = app(OrderReturnService::class)->processRefund($orderReturn, [
            'refund_amount' => 0.00,
            'refund_method' => 'none',
        ]);

        $this->assertEquals(0.00, (float) $orderReturn->refund_amount);

        // 4. Verify balanced journal entry on General Ledger
        $entry = \App\Models\JournalEntry::with('lines')
            ->where('reference_type', 'OrderReturn')
            ->where('reference_id', $orderReturn->id)
            ->first();
        $this->assertNotNull($entry, 'Journal entry must exist on GL for OrderReturn.');

        $totalDebits = $entry->lines->sum('debit');
        $totalCredits = $entry->lines->sum('credit');

        $this->assertEquals($totalDebits, $totalCredits, 'General Ledger entry must strictly balance debits and credits.');
        $this->assertEquals(1100.00, (float) $totalDebits, 'Total debits must match full unpaid COD order amount.');
    }
}
