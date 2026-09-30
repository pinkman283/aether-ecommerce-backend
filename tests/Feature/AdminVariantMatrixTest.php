<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminVariantMatrixTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_matrix_tester@example.com'],
            [
                'name' => 'Admin Matrix Tester',
                'password' => bcrypt('secret123'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        $this->category = Category::firstOrCreate(
            ['slug' => 'test-matrix-hardware-cat'],
            [
                'name' => 'Test Matrix Hardware Category',
                'description' => 'Test category for variant matrix tests',
            ]
        );
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);
    }

    private function createTestProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Matrix Device ' . Str::random(5),
            'slug' => 'matrix-device-' . Str::random(8),
            'category_id' => $this->category->id,
            'price' => 399.00,
            'stock_quantity' => 0,
            'description' => 'Test hardware device for matrix testing.',
        ], $overrides));
    }

    /**
     * Test 1: Duplicate color + size combinations are rejected with HTTP 422.
     */
    public function test_1_duplicate_color_and_size_combinations_are_rejected(): void
    {
        $this->actAsAdmin();

        $product = $this->createTestProduct();

        $payload = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => 399.00,
            'images' => [
                ['image_url' => 'https://example.com/matrix.jpg', 'is_primary' => true],
            ],
            'variants' => [
                [
                    'name' => 'Black / 49mm',
                    'color_name' => 'Black',
                    'color_hex' => '#000000',
                    'size' => '49mm',
                    'stock_quantity' => 10,
                ],
                [
                    'name' => 'Black / 49mm',
                    'color_name' => 'Black',
                    'color_hex' => '#000000',
                    'size' => '49mm',
                    'stock_quantity' => 15,
                ],
            ],
        ];

        $response = $this->putJson("/api/admin/products/{$product->id}", $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['variants']);
        $this->assertStringContainsString('Duplicate combination', $response->json('errors.variants.0'));
    }

    /**
     * Test 2: Case/whitespace duplicates are rejected with HTTP 422.
     */
    public function test_2_case_and_whitespace_duplicate_combinations_are_rejected(): void
    {
        $this->actAsAdmin();

        $product = $this->createTestProduct();

        $payload = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => 399.00,
            'images' => [
                ['image_url' => 'https://example.com/matrix.jpg', 'is_primary' => true],
            ],
            'variants' => [
                [
                    'name' => 'Black / 49mm',
                    'color_name' => 'Black',
                    'color_hex' => '#000000',
                    'size' => '49mm',
                    'stock_quantity' => 10,
                ],
                [
                    'name' => 'black / 49MM (case & whitespace test)',
                    'color_name' => '  black  ',
                    'color_hex' => '#000000',
                    'size' => ' 49MM ',
                    'stock_quantity' => 12,
                ],
            ],
        ];

        $response = $this->putJson("/api/admin/products/{$product->id}", $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['variants']);
        $this->assertStringContainsString('Duplicate combination', $response->json('errors.variants.0'));
    }

    /**
     * Test 3: Valid Cartesian combinations can be saved successfully.
     */
    public function test_3_valid_cartesian_combinations_can_be_saved(): void
    {
        $this->actAsAdmin();

        $product = $this->createTestProduct();

        $payload = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => 399.00,
            'images' => [
                ['image_url' => 'https://example.com/matrix.jpg', 'is_primary' => true],
            ],
            'variants' => [
                [
                    'name' => 'Black / 44mm',
                    'color_name' => 'Black',
                    'color_hex' => '#000000',
                    'size' => '44mm',
                    'stock_quantity' => 20,
                    'price_modifier' => 0.00,
                ],
                [
                    'name' => 'Black / 49mm',
                    'color_name' => 'Black',
                    'color_hex' => '#000000',
                    'size' => '49mm',
                    'stock_quantity' => 25,
                    'price_modifier' => 50.00,
                ],
                [
                    'name' => 'Silver / 44mm',
                    'color_name' => 'Silver',
                    'color_hex' => '#CCCCCC',
                    'size' => '44mm',
                    'stock_quantity' => 15,
                    'price_modifier' => 0.00,
                ],
                [
                    'name' => 'Silver / 49mm',
                    'color_name' => 'Silver',
                    'color_hex' => '#CCCCCC',
                    'size' => '49mm',
                    'stock_quantity' => 10,
                    'price_modifier' => 50.00,
                ],
            ],
        ];

        $response = $this->putJson("/api/admin/products/{$product->id}", $payload);

        $response->assertStatus(200);
        $this->assertCount(4, $product->fresh()->variants);
        // Stock sync: 20 + 25 + 15 + 10 = 70
        $this->assertEquals(70, $product->fresh()->stock_quantity);
    }

    /**
     * Test 4: Existing variant IDs are preserved when updating existing combinations.
     */
    public function test_4_existing_variant_ids_are_preserved(): void
    {
        $this->actAsAdmin();

        $product = $this->createTestProduct();

        $var1 = $product->variants()->create([
            'name' => 'Black / 44mm',
            'color_name' => 'Black',
            'color_hex' => '#000000',
            'size' => '44mm',
            'stock_quantity' => 20,
            'price_modifier' => 0.00,
            'sku' => 'SKU-ORIG-101',
        ]);

        $var2 = $product->variants()->create([
            'name' => 'Black / 49mm',
            'color_name' => 'Black',
            'color_hex' => '#000000',
            'size' => '49mm',
            'stock_quantity' => 25,
            'price_modifier' => 50.00,
            'sku' => 'SKU-ORIG-102',
        ]);

        $payload = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => 399.00,
            'images' => [
                ['image_url' => 'https://example.com/matrix.jpg', 'is_primary' => true],
            ],
            'variants' => [
                [
                    'id' => $var1->id,
                    'name' => 'Black / 44mm',
                    'color_name' => 'Black',
                    'color_hex' => '#000000',
                    'size' => '44mm',
                    'stock_quantity' => 30, // Updated stock
                    'price_modifier' => 5.00,
                ],
                [
                    'id' => $var2->id,
                    'name' => 'Black / 49mm',
                    'color_name' => 'Black',
                    'color_hex' => '#000000',
                    'size' => '49mm',
                    'stock_quantity' => 35,
                    'price_modifier' => 55.00,
                ],
            ],
        ];

        $response = $this->putJson("/api/admin/products/{$product->id}", $payload);

        $response->assertStatus(200);

        $reconciledVar1 = ProductVariant::find($var1->id);
        $reconciledVar2 = ProductVariant::find($var2->id);

        $this->assertNotNull($reconciledVar1);
        $this->assertNotNull($reconciledVar2);
        $this->assertEquals($var1->id, $reconciledVar1->id);
        $this->assertEquals($var2->id, $reconciledVar2->id);
        $this->assertEquals(30, $reconciledVar1->stock_quantity);
        $this->assertEquals(35, $reconciledVar2->stock_quantity);
    }

    /**
     * Test 5: Existing variant SKU is preserved across matrix updates.
     */
    public function test_5_existing_variant_sku_is_preserved(): void
    {
        $this->actAsAdmin();

        $product = $this->createTestProduct();

        $var = $product->variants()->create([
            'name' => 'Titanium / 49mm',
            'color_name' => 'Titanium',
            'color_hex' => '#94A3B8',
            'size' => '49mm',
            'stock_quantity' => 15,
            'price_modifier' => 100.00,
            'sku' => 'WATCH-PRO-TIT-49',
        ]);

        $payload = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => 499.00,
            'images' => [
                ['image_url' => 'https://example.com/matrix.jpg', 'is_primary' => true],
            ],
            'variants' => [
                [
                    'id' => $var->id,
                    'name' => 'Titanium / 49mm (Updated)',
                    'color_name' => 'Titanium',
                    'color_hex' => '#94A3B8',
                    'size' => '49mm',
                    'stock_quantity' => 18,
                    'price_modifier' => 110.00,
                ],
            ],
        ];

        $response = $this->putJson("/api/admin/products/{$product->id}", $payload);
        $response->assertStatus(200);

        $this->assertEquals('WATCH-PRO-TIT-49', ProductVariant::find($var->id)->sku);
    }

    /**
     * Test 6: Existing stock is preserved when updating matching combination.
     */
    public function test_6_existing_stock_is_preserved_when_updating_matching_combination(): void
    {
        $this->actAsAdmin();

        $product = $this->createTestProduct();

        $var = $product->variants()->create([
            'name' => 'Alpine Olive / 44mm',
            'color_name' => 'Alpine Olive',
            'color_hex' => '#3F6212',
            'size' => '44mm',
            'stock_quantity' => 42,
            'price_modifier' => 0.00,
            'cost_price' => 180.00,
        ]);

        $payload = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => 399.00,
            'images' => [
                ['image_url' => 'https://example.com/matrix.jpg', 'is_primary' => true],
            ],
            'variants' => [
                [
                    'id' => $var->id,
                    'name' => 'Alpine Olive / 44mm',
                    'color_name' => 'Alpine Olive',
                    'color_hex' => '#3F6212',
                    'size' => '44mm',
                    'stock_quantity' => 42, // Preserved stock
                    'cost_price' => 180.00, // Preserved cost
                ],
            ],
        ];

        $response = $this->putJson("/api/admin/products/{$product->id}", $payload);
        $response->assertStatus(200);

        $fresh = ProductVariant::find($var->id);
        $this->assertEquals(42, $fresh->stock_quantity);
        $this->assertEquals(180.00, $fresh->cost_price);
    }

    /**
     * Test 7: Existing barcode is preserved across matrix updates.
     */
    public function test_7_existing_barcode_is_preserved(): void
    {
        $this->actAsAdmin();

        $product = $this->createTestProduct();

        $var = $product->variants()->create([
            'name' => 'Cyber Cyan / 1TB',
            'color_name' => 'Cyber Cyan',
            'color_hex' => '#06B6D4',
            'size' => '1TB',
            'stock_quantity' => 8,
            'barcode' => '8809123456789',
        ]);

        $payload = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => 699.00,
            'images' => [
                ['image_url' => 'https://example.com/matrix.jpg', 'is_primary' => true],
            ],
            'variants' => [
                [
                    'id' => $var->id,
                    'name' => 'Cyber Cyan / 1TB',
                    'color_name' => 'Cyber Cyan',
                    'color_hex' => '#06B6D4',
                    'size' => '1TB',
                    'stock_quantity' => 8,
                    'barcode' => '8809123456789',
                ],
            ],
        ];

        $response = $this->putJson("/api/admin/products/{$product->id}", $payload);
        $response->assertStatus(200);

        $this->assertEquals('8809123456789', ProductVariant::find($var->id)->barcode);
    }

    /**
     * Test 8: Cross-product variant IDs remain rejected with HTTP 422.
     */
    public function test_8_cross_product_variant_ids_remain_rejected(): void
    {
        $this->actAsAdmin();

        $productA = $this->createTestProduct(['name' => 'Product A']);
        $productB = $this->createTestProduct(['name' => 'Product B']);

        $varB = $productB->variants()->create([
            'name' => 'Product B Option',
            'stock_quantity' => 10,
        ]);

        $payload = [
            'name' => $productA->name,
            'category_id' => $productA->category_id,
            'price' => 299.00,
            'images' => [
                ['image_url' => 'https://example.com/matrix.jpg', 'is_primary' => true],
            ],
            'variants' => [
                [
                    'id' => $varB->id, // Malicious / cross-product ID
                    'name' => 'Infiltrated Variant',
                    'color_name' => 'Infiltrator',
                    'size' => '100mm',
                    'stock_quantity' => 999,
                ],
            ],
        ];

        $response = $this->putJson("/api/admin/products/{$productA->id}", $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['variants']);
        $this->assertStringContainsString('does not belong to this product', $response->json('errors.variants.0'));
    }

    /**
     * Test 9: Generic name-only variants survive alongside matrix updates.
     */
    public function test_9_generic_name_only_variants_survive_matrix_update(): void
    {
        $this->actAsAdmin();

        $product = $this->createTestProduct();

        // Create an existing generic switch variant (null color, null size)
        $genericVar = $product->variants()->create([
            'name' => 'Red Linear Switch',
            'color_name' => null,
            'color_hex' => null,
            'size' => null,
            'stock_quantity' => 50,
            'price_modifier' => 0.00,
            'sku' => 'SW-RED-LIN',
        ]);

        // Submit both the preserved generic variant and a new matrix combination
        $payload = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => 149.00,
            'images' => [
                ['image_url' => 'https://example.com/matrix.jpg', 'is_primary' => true],
            ],
            'variants' => [
                [
                    'id' => $genericVar->id,
                    'name' => 'Red Linear Switch',
                    'color_name' => null,
                    'color_hex' => null,
                    'size' => null,
                    'stock_quantity' => 50,
                ],
                [
                    // New matrix combination
                    'name' => 'Space Gray / TKL',
                    'color_name' => 'Space Gray',
                    'color_hex' => '#64748B',
                    'size' => 'TKL',
                    'stock_quantity' => 15,
                ],
            ],
        ];

        $response = $this->putJson("/api/admin/products/{$product->id}", $payload);

        $response->assertStatus(200);

        $freshGeneric = ProductVariant::find($genericVar->id);
        $this->assertNotNull($freshGeneric);
        $this->assertEquals('Red Linear Switch', $freshGeneric->name);
        $this->assertNull($freshGeneric->color_name);
        $this->assertNull($freshGeneric->size);
        $this->assertEquals(50, $freshGeneric->stock_quantity);

        // Verify total variants = 2
        $this->assertCount(2, $product->fresh()->variants);
        $this->assertEquals(65, $product->fresh()->stock_quantity);
    }

    /**
     * Test 10: New matrix combinations receive new IDs after persistence.
     */
    public function test_10_new_matrix_combinations_receive_new_ids_after_persistence(): void
    {
        $this->actAsAdmin();

        $product = $this->createTestProduct();

        $var1 = $product->variants()->create([
            'name' => 'Black / 44mm',
            'color_name' => 'Black',
            'color_hex' => '#000000',
            'size' => '44mm',
            'stock_quantity' => 10,
        ]);

        $payload = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => 399.00,
            'images' => [
                ['image_url' => 'https://example.com/matrix.jpg', 'is_primary' => true],
            ],
            'variants' => [
                [
                    'id' => $var1->id,
                    'name' => 'Black / 44mm',
                    'color_name' => 'Black',
                    'color_hex' => '#000000',
                    'size' => '44mm',
                    'stock_quantity' => 10,
                ],
                [
                    // Brand new combination without ID
                    'name' => 'Silver / 49mm',
                    'color_name' => 'Silver',
                    'color_hex' => '#CCCCCC',
                    'size' => '49mm',
                    'stock_quantity' => 12,
                ],
            ],
        ];

        $response = $this->putJson("/api/admin/products/{$product->id}", $payload);
        $response->assertStatus(200);

        $variants = $product->fresh()->variants;
        $this->assertCount(2, $variants);

        $newVariant = $variants->firstWhere('size', '49mm');
        $this->assertNotNull($newVariant);
        $this->assertNotEquals($var1->id, $newVariant->id);
        $this->assertGreaterThan(0, $newVariant->id);
        $this->assertNotEmpty($newVariant->sku);
    }
}
