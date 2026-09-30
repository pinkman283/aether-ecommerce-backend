<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductStockSyncTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_stock_sync_tester@example.com'],
            [
                'name' => 'Admin Stock Sync Tester',
                'password' => bcrypt('secret123'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        $this->category = Category::firstOrCreate(
            ['slug' => 'test-stock-sync-cat'],
            ['name' => 'Stock Sync Test Category', 'description' => 'Test category for stock sync']
        );
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);
    }

    private function createProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Sync Product ' . Str::random(5),
            'slug' => 'sync-prod-' . Str::random(8),
            'category_id' => $this->category->id,
            'price' => 50.00,
            'cost_price' => 25.00,
            'stock_quantity' => 10,
            'description' => 'Test product for stock synchronization.',
            'is_active' => true,
        ], $overrides));
    }

    public function test_product_without_variants_can_receive_normal_stock_adjustment(): void
    {
        $this->actAsAdmin();

        $product = $this->createProduct(['stock_quantity' => 10]);

        $response = $this->postJson("/api/admin/inventory/{$product->id}/adjust", [
            'adjustment' => 5,
            'reason' => 'Warehouse Restock',
            'unit_cost' => 25.00,
        ]);

        $response->assertOk();
        $this->assertEquals(15, $product->fresh()->stock_quantity);
    }

    public function test_product_with_variants_cannot_be_adjusted_through_parent_only_adjustment(): void
    {
        $this->actAsAdmin();

        $product = $this->createProduct(['stock_quantity' => 10]);
        ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Color Black',
            'color_name' => 'Black',
            'stock_quantity' => 10,
            'sku' => $product->sku . '-BLK',
        ]);

        // Attempting to adjust parent product directly without variant_id should fail
        $response = $this->postJson("/api/admin/inventory/{$product->id}/adjust", [
            'adjustment' => 5,
            'reason' => 'Direct Parent Attempt',
            'unit_cost' => 25.00,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'VARIANT_REQUIRED_FOR_STOCK_ADJUSTMENT');

        // Verify stock was not changed
        $this->assertEquals(10, $product->fresh()->stock_quantity);
    }

    public function test_variant_stock_adjustment_updates_parent_stock_correctly(): void
    {
        $this->actAsAdmin();

        $product = $this->createProduct(['stock_quantity' => 15]);
        $v1 = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant A',
            'stock_quantity' => 10,
            'sku' => $product->sku . '-V1',
        ]);
        $v2 = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant B',
            'stock_quantity' => 5,
            'sku' => $product->sku . '-V2',
        ]);

        // Adjust Variant A by +7
        $response = $this->postJson("/api/admin/inventory/{$product->id}/adjust", [
            'variant_id' => $v1->id,
            'adjustment' => 7,
            'reason' => 'Restock Variant A',
            'unit_cost' => 25.00,
        ]);

        $response->assertOk();

        // Variant A should be 17
        $this->assertEquals(17, $v1->fresh()->stock_quantity);
        // Variant B remains 5
        $this->assertEquals(5, $v2->fresh()->stock_quantity);
        // Parent product should be 17 + 5 = 22
        $this->assertEquals(22, $product->fresh()->stock_quantity);
    }

    public function test_reconciliation_dry_run_reports_mismatches_without_changing_data(): void
    {
        $product = $this->createProduct(['stock_quantity' => 100]); // Intentional mismatch: 100 vs variants sum 25
        ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant Alpha',
            'stock_quantity' => 15,
            'sku' => $product->sku . '-AL',
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant Beta',
            'stock_quantity' => 10,
            'sku' => $product->sku . '-BE',
        ]);

        // Run dry-run
        $exitCode = Artisan::call('products:sync-variant-stock', ['--dry-run' => true]);
        $this->assertEquals(0, $exitCode);

        // Verify product stock was NOT changed by dry run
        $this->assertEquals(100, $product->fresh()->stock_quantity);
    }

    public function test_reconciliation_fix_repairs_mismatches(): void
    {
        $product = $this->createProduct(['stock_quantity' => 100]);
        ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant Alpha',
            'stock_quantity' => 15,
            'sku' => $product->sku . '-AL',
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant Beta',
            'stock_quantity' => 10,
            'sku' => $product->sku . '-BE',
        ]);

        // Run --fix
        $exitCode = Artisan::call('products:sync-variant-stock', ['--fix' => true]);
        $this->assertEquals(0, $exitCode);

        // Verify parent stock is now repaired to 25
        $this->assertEquals(25, $product->fresh()->stock_quantity);
    }

    public function test_reconciliation_does_not_modify_variant_stock(): void
    {
        $product = $this->createProduct(['stock_quantity' => 99]);
        $v1 = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant 1',
            'stock_quantity' => 18,
            'sku' => $product->sku . '-1',
        ]);
        $v2 = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant 2',
            'stock_quantity' => 12,
            'sku' => $product->sku . '-2',
        ]);

        Artisan::call('products:sync-variant-stock', ['--fix' => true]);

        // Variants should remain untouched
        $this->assertEquals(18, $v1->fresh()->stock_quantity);
        $this->assertEquals(12, $v2->fresh()->stock_quantity);
    }

    public function test_reconciliation_does_not_modify_inventory_movements(): void
    {
        $movementsBefore = InventoryMovement::count();

        $product = $this->createProduct(['stock_quantity' => 50]);
        ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant 1',
            'stock_quantity' => 20,
            'sku' => $product->sku . '-1',
        ]);

        Artisan::call('products:sync-variant-stock', ['--fix' => true]);

        $movementsAfter = InventoryMovement::count();
        $this->assertEquals($movementsBefore, $movementsAfter, "Reconciliation must not create fake inventory movements.");
    }

    public function test_reconciliation_does_not_modify_fifo_cost_layers(): void
    {
        $layersBefore = InventoryCostLayer::count();

        $product = $this->createProduct(['stock_quantity' => 50]);
        ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant 1',
            'stock_quantity' => 20,
            'sku' => $product->sku . '-1',
        ]);

        Artisan::call('products:sync-variant-stock', ['--fix' => true]);

        $layersAfter = InventoryCostLayer::count();
        $this->assertEquals($layersBefore, $layersAfter, "Reconciliation must not touch FIFO cost layers.");
    }

    public function test_running_reconciliation_twice_is_idempotent(): void
    {
        $product = $this->createProduct(['stock_quantity' => 77]);
        ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant 1',
            'stock_quantity' => 30,
            'sku' => $product->sku . '-1',
        ]);

        // First fix
        Artisan::call('products:sync-variant-stock', ['--fix' => true]);
        $this->assertEquals(30, $product->fresh()->stock_quantity);

        // Second fix immediately after
        Artisan::call('products:sync-variant-stock', ['--fix' => true]);
        $this->assertEquals(30, $product->fresh()->stock_quantity);

        // Dry run now finds 0 mismatches
        $output = Artisan::output();
        Artisan::call('products:sync-variant-stock', ['--dry-run' => true]);
        $dryRunOutput = Artisan::output();
        $this->assertStringContainsString("perfectly synchronized", $dryRunOutput);
    }
}
