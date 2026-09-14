<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\InventoryCostingService;
use Tests\TestCase;

class InventoryFifoRemediationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Category::firstOrCreate(['id' => 1], [
            'name' => 'General Audio',
            'slug' => 'general-audio',
            'description' => 'Test audio'
        ]);
    }

    public function test_product_syncs_stock_from_variants(): void
    {
        $product = Product::create([
            'category_id' => 1,
            'name' => 'Test Variant Sync Keyboard',
            'slug' => 'test-sync-' . uniqid(),
            'sku' => 'SYNC-' . strtoupper(uniqid()),
            'price' => 150.00,
            'stock_quantity' => 0,
            'description' => 'Test sync',
        ]);

        $product->variants()->create([
            'name' => 'Red Switch',
            'sku' => $product->sku . '-RED',
            'stock_quantity' => 15,
            'price_modifier' => 0.00,
        ]);

        $product->variants()->create([
            'name' => 'Blue Switch',
            'sku' => $product->sku . '-BLUE',
            'stock_quantity' => 25,
            'price_modifier' => 0.00,
        ]);

        $product->syncStockFromVariants();
        $this->assertEquals(40, $product->fresh()->stock_quantity);
    }

    public function test_fulfillment_consumes_fifo_layers_correctly(): void
    {
        $product = Product::create([
            'category_id' => 1,
            'name' => 'FIFO Earbuds',
            'slug' => 'fifo-earbuds-' . uniqid(),
            'sku' => 'FIFO-' . strtoupper(uniqid()),
            'price' => 200.00,
            'cost_price' => 80.00,
            'stock_quantity' => 20,
            'description' => 'FIFO test product',
        ]);

        // Layer 1: 5 units @ $70.00
        $layer1 = InventoryCostLayer::create([
            'product_id' => $product->id,
            'unit_cost' => 70.00,
            'initial_quantity' => 5,
            'remaining_quantity' => 5,
            'is_depleted' => false,
        ]);

        // Layer 2: 10 units @ $90.00
        $layer2 = InventoryCostLayer::create([
            'product_id' => $product->id,
            'unit_cost' => 90.00,
            'initial_quantity' => 10,
            'remaining_quantity' => 10,
            'is_depleted' => false,
        ]);

        $order = Order::create([
            'order_number' => 'ORD-FIFO-' . strtoupper(uniqid()),
            'customer_name' => 'Test Customer',
            'customer_phone' => '01711111111',
            'customer_email' => 'fifo@example.com',
            'shipping_address' => ['city' => 'Dhaka'],
            'subtotal' => 1600.00,
            'total_amount' => 1600.00,
            'payment_status' => 'paid',
            'order_status' => 'processing',
        ]);

        // Buy 8 units: should consume 5 units @ $70 ($350) + 3 units @ $90 ($270) = Total COGS $620
        $item = $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'unit_price' => 200.00,
            'quantity' => 8,
            'total_price' => 1600.00,
        ]);

        InventoryCostingService::fulfillOrderAndComputeCogs($order);

        $order->refresh();
        $this->assertEquals(620.00, (float) $order->cogs_amount);
        $this->assertEquals(980.00, (float) $order->gross_profit);

        $layer1->refresh();
        $layer2->refresh();
        $this->assertEquals(0, $layer1->remaining_quantity);
        $this->assertTrue((bool)$layer1->is_depleted);
        $this->assertEquals(7, $layer2->remaining_quantity);
        $this->assertFalse((bool)$layer2->is_depleted);

        // Idempotency: second call does not re-consume
        InventoryCostingService::fulfillOrderAndComputeCogs($order);
        $layer2->refresh();
        $this->assertEquals(7, $layer2->remaining_quantity);
    }

    public function test_fulfillment_halts_on_uncosted_inventory_without_fabricating_cost(): void
    {
        $product = Product::create([
            'category_id' => 1,
            'name' => 'Uncosted Mystery Hardware',
            'slug' => 'uncosted-' . uniqid(),
            'sku' => 'UNC-' . strtoupper(uniqid()),
            'price' => 500.00,
            'cost_price' => null, // No cost basis whatsoever
            'stock_quantity' => 10,
            'description' => 'Uncosted item',
        ]);

        $order = Order::create([
            'order_number' => 'ORD-UNC-' . strtoupper(uniqid()),
            'customer_name' => 'Test Customer',
            'customer_phone' => '01722222222',
            'customer_email' => 'unc@example.com',
            'shipping_address' => ['city' => 'Dhaka'],
            'subtotal' => 500.00,
            'total_amount' => 500.00,
            'payment_status' => 'paid',
            'order_status' => 'processing',
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'unit_price' => 500.00,
            'quantity' => 2,
            'total_price' => 1000.00,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/has no valid FIFO cost layer or documented cost price/');

        InventoryCostingService::fulfillOrderAndComputeCogs($order);
    }

    public function test_manual_adjustment_creates_fifo_layer_and_audit_movement(): void
    {
        $user = User::first() ?? User::factory()->create();

        $product = Product::create([
            'category_id' => 1,
            'name' => 'Adjustment Target Mouse',
            'slug' => 'adj-mouse-' . uniqid(),
            'sku' => 'MS-ADJ-' . strtoupper(uniqid()),
            'price' => 60.00,
            'cost_price' => 25.00,
            'stock_quantity' => 5,
            'description' => 'Adjustment test',
        ]);

        $movement = InventoryCostingService::adjustStockManually(
            $product,
            null,
            10,
            'Warehouse Stock Restock',
            $user,
            30.00
        );

        $this->assertEquals(15, $product->fresh()->stock_quantity);
        $this->assertEquals(30.00, (float)$movement->unit_cost);
        $this->assertEquals(300.00, (float)$movement->total_cost);
        $this->assertEquals('manual_adjustment', $movement->movement_type);

        $costLayer = InventoryCostLayer::where('product_id', $product->id)->latest()->first();
        $this->assertNotNull($costLayer);
        $this->assertEquals(30.00, (float)$costLayer->unit_cost);
        $this->assertEquals(10, $costLayer->remaining_quantity);
    }
}
