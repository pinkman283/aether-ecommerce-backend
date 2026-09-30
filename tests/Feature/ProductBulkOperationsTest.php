<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductBulkOperationsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Category $categoryA;
    private Category $categoryB;
    private Brand $brandA;
    private Brand $brandB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_bulk_ops_tester@example.com'],
            [
                'name' => 'Admin Bulk Ops Tester',
                'password' => bcrypt('secret123'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        $this->categoryA = Category::firstOrCreate(
            ['slug' => 'test-bulk-cat-a'],
            ['name' => 'Bulk Cat A', 'description' => 'Category A for bulk tests']
        );

        $this->categoryB = Category::firstOrCreate(
            ['slug' => 'test-bulk-cat-b'],
            ['name' => 'Bulk Cat B', 'description' => 'Category B for bulk tests']
        );

        $this->brandA = Brand::firstOrCreate(
            ['slug' => 'test-bulk-brand-a'],
            ['name' => 'Bulk Brand Alpha']
        );

        $this->brandB = Brand::firstOrCreate(
            ['slug' => 'test-bulk-brand-b'],
            ['name' => 'Bulk Brand Beta']
        );
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);
    }

    private function createProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Bulk Test Product ' . Str::random(5),
            'slug' => 'bulk-test-' . Str::random(8),
            'category_id' => $this->categoryA->id,
            'brand_id' => $this->brandA->id,
            'brand' => $this->brandA->name,
            'price' => 100.00,
            'cost_price' => 50.00,
            'compare_at_price' => 120.00,
            'stock_quantity' => 10,
            'description' => 'Test product for bulk operations.',
            'is_active' => true,
        ], $overrides));
    }

    public function test_bulk_activate_sets_products_active(): void
    {
        $this->actAsAdmin();

        $p1 = $this->createProduct(['is_active' => false]);
        $p2 = $this->createProduct(['is_active' => false]);

        $response = $this->postJson('/api/admin/products/bulk-update', [
            'product_ids' => [$p1->id, $p2->id],
            'operation' => 'activate',
        ]);

        $response->assertOk()
            ->assertJsonPath('operation', 'activate')
            ->assertJsonPath('affected_count', 2);

        $this->assertTrue((bool) $p1->fresh()->is_active);
        $this->assertTrue((bool) $p2->fresh()->is_active);
    }

    public function test_bulk_deactivate_sets_products_inactive(): void
    {
        $this->actAsAdmin();

        $p1 = $this->createProduct(['is_active' => true]);
        $p2 = $this->createProduct(['is_active' => true]);

        $response = $this->postJson('/api/admin/products/bulk-update', [
            'product_ids' => [$p1->id, $p2->id],
            'operation' => 'deactivate',
        ]);

        $response->assertOk()
            ->assertJsonPath('operation', 'deactivate')
            ->assertJsonPath('affected_count', 2);

        $this->assertFalse((bool) $p1->fresh()->is_active);
        $this->assertFalse((bool) $p2->fresh()->is_active);
    }

    public function test_bulk_category_assignment_updates_category_for_all_products(): void
    {
        $this->actAsAdmin();

        $p1 = $this->createProduct(['category_id' => $this->categoryA->id]);
        $p2 = $this->createProduct(['category_id' => $this->categoryA->id]);

        $response = $this->postJson('/api/admin/products/bulk-update', [
            'product_ids' => [$p1->id, $p2->id],
            'operation' => 'category',
            'category_id' => $this->categoryB->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('operation', 'category')
            ->assertJsonPath('affected_count', 2);

        $this->assertEquals($this->categoryB->id, $p1->fresh()->category_id);
        $this->assertEquals($this->categoryB->id, $p2->fresh()->category_id);
    }

    public function test_bulk_brand_assignment_updates_brand_for_all_products(): void
    {
        $this->actAsAdmin();

        $p1 = $this->createProduct(['brand_id' => $this->brandA->id, 'brand' => $this->brandA->name]);
        $p2 = $this->createProduct(['brand_id' => $this->brandA->id, 'brand' => $this->brandA->name]);

        $response = $this->postJson('/api/admin/products/bulk-update', [
            'product_ids' => [$p1->id, $p2->id],
            'operation' => 'brand',
            'brand_id' => $this->brandB->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('operation', 'brand')
            ->assertJsonPath('affected_count', 2);

        $this->assertEquals($this->brandB->id, $p1->fresh()->brand_id);
        $this->assertEquals($this->brandB->name, $p1->fresh()->brand);
        $this->assertEquals($this->brandB->id, $p2->fresh()->brand_id);
        $this->assertEquals($this->brandB->name, $p2->fresh()->brand);
    }

    public function test_bulk_price_adjustment_percentage_increase(): void
    {
        $this->actAsAdmin();

        $p1 = $this->createProduct(['price' => 100.00]);
        $p2 = $this->createProduct(['price' => 200.00]);

        $response = $this->postJson('/api/admin/products/bulk-update', [
            'product_ids' => [$p1->id, $p2->id],
            'operation' => 'price',
            'adjustment_type' => 'percentage',
            'value' => 10, // +10%
            'rounding' => 'none',
        ]);

        $response->assertOk()
            ->assertJsonPath('operation', 'price')
            ->assertJsonPath('affected_count', 2);

        $this->assertEquals(110.00, (float) $p1->fresh()->price);
        $this->assertEquals(220.00, (float) $p2->fresh()->price);
    }

    public function test_bulk_price_adjustment_fixed_decrease(): void
    {
        $this->actAsAdmin();

        $p1 = $this->createProduct(['price' => 100.00]);
        $p2 = $this->createProduct(['price' => 50.00]);

        $response = $this->postJson('/api/admin/products/bulk-update', [
            'product_ids' => [$p1->id, $p2->id],
            'operation' => 'price',
            'adjustment_type' => 'fixed',
            'value' => -15.50, // -$15.50
            'rounding' => 'none',
        ]);

        $response->assertOk()
            ->assertJsonPath('operation', 'price')
            ->assertJsonPath('affected_count', 2);

        $this->assertEquals(84.50, (float) $p1->fresh()->price);
        $this->assertEquals(34.50, (float) $p2->fresh()->price);
    }

    public function test_bulk_price_adjustment_with_retail_99_rounding(): void
    {
        $this->actAsAdmin();

        $p = $this->createProduct(['price' => 20.00]);

        $response = $this->postJson('/api/admin/products/bulk-update', [
            'product_ids' => [$p->id],
            'operation' => 'price',
            'adjustment_type' => 'percentage',
            'value' => 12.5, // 20 + 2.5 = 22.50 -> retail .99 = 22.99
            'rounding' => 'round_99',
        ]);

        $response->assertOk();
        $this->assertEquals(22.99, (float) $p->fresh()->price);
    }

    public function test_bulk_price_adjustment_rejects_negative_price_and_rolls_back(): void
    {
        $this->actAsAdmin();

        $p1 = $this->createProduct(['price' => 100.00]);
        $p2 = $this->createProduct(['price' => 20.00]); // will become -30

        $response = $this->postJson('/api/admin/products/bulk-update', [
            'product_ids' => [$p1->id, $p2->id],
            'operation' => 'price',
            'adjustment_type' => 'fixed',
            'value' => -50.00,
            'rounding' => 'none',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['value']);

        // Verify atomicity: p1 price was NOT changed despite coming first
        $this->assertEquals(100.00, (float) $p1->fresh()->price);
        $this->assertEquals(20.00, (float) $p2->fresh()->price);
    }

    public function test_bulk_update_requires_valid_operation(): void
    {
        $this->actAsAdmin();

        $p = $this->createProduct();

        $response = $this->postJson('/api/admin/products/bulk-update', [
            'product_ids' => [$p->id],
            'operation' => 'invalid_op',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['operation']);
    }

    public function test_bulk_update_does_not_modify_cost_or_compare_at_price(): void
    {
        $this->actAsAdmin();

        $p = $this->createProduct([
            'price' => 100.00,
            'cost_price' => 45.00,
            'compare_at_price' => 130.00,
        ]);

        $response = $this->postJson('/api/admin/products/bulk-update', [
            'product_ids' => [$p->id],
            'operation' => 'price',
            'adjustment_type' => 'percentage',
            'value' => 20,
        ]);

        $response->assertOk();

        $fresh = $p->fresh();
        $this->assertEquals(120.00, (float) $fresh->price);
        $this->assertEquals(45.00, (float) $fresh->cost_price);
        $this->assertEquals(130.00, (float) $fresh->compare_at_price);
    }
}
