<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class CartValidationTest extends TestCase
{
    use DatabaseTransactions;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::firstOrCreate(
            ['slug' => 'test-cart-cat'],
            ['name' => 'Cart Test Category', 'description' => 'Category for cart testing']
        );
    }

    private function createProductWithStock(int $stock, float $price = 50.00, bool $isActive = true): Product
    {
        $unique = Str::random(8);
        return Product::create([
            'category_id' => $this->category->id,
            'name' => 'Cart Product ' . $unique,
            'slug' => 'cart-prod-' . strtolower($unique),
            'price' => $price,
            'cost_price' => 25.00, // Sensitive cost field that must never leak
            'stock_quantity' => $stock,
            'is_active' => $isActive,
            'description' => 'Cart test product.',
        ]);
    }

    private function createVariantWithStock(Product $product, int $stock, float $priceMod = 10.00): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant ' . Str::random(5),
            'sku' => 'SKU-' . Str::upper(Str::random(6)),
            'stock_quantity' => $stock,
            'cost_price' => 15.00,
            'price_modifier' => $priceMod,
        ]);
    }

    /**
     * Test valid cart within stock succeeds and strictly excludes cost_price.
     */
    public function test_valid_cart_within_stock_succeeds(): void
    {
        $product = $this->createProductWithStock(10, 60.00);
        $variant = $this->createVariantWithStock($product, 5, 15.00);

        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 2,
                    'price' => 75.00,
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'is_valid' => true,
                'has_changes' => false,
                'summary' => [
                    'total_items' => 2,
                    'subtotal' => 150.00,
                    'has_out_of_stock' => false,
                    'has_price_changes' => false,
                    'has_inactive_items' => false,
                ],
            ]);

        $item = $response->json('items.0');
        $this->assertEquals('valid', $item['status']);
        $this->assertEquals(2, $item['normalized_quantity']);
        $this->assertEquals(75.00, $item['unit_price']);

        // Assert cost_price is absent everywhere
        $response->assertJsonMissing(['cost_price']);
        $this->assertStringNotContainsString('cost_price', $response->getContent());
    }

    /**
     * Test product deleted or non-existent in cart is detected and marked invalid.
     */
    public function test_non_existent_product_in_cart_is_invalidated(): void
    {
        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                [
                    'product_id' => 999999,
                    'variant_id' => null,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'is_valid' => false,
                'has_changes' => true,
            ]);

        $item = $response->json('items.0');
        $this->assertEquals('product_not_found', $item['status']);
        $this->assertFalse($item['is_valid']);
        $this->assertEquals(0, $item['normalized_quantity']);
    }

    /**
     * Test inactive product in cart is detected and marked invalid.
     */
    public function test_inactive_product_in_cart_is_rejected(): void
    {
        $product = $this->createProductWithStock(10, 50.00, false);

        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => null,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'is_valid' => false,
                'has_changes' => true,
                'summary' => [
                    'has_inactive_items' => true,
                ],
            ]);

        $item = $response->json('items.0');
        $this->assertEquals('inactive', $item['status']);
        $this->assertFalse($item['is_valid']);
    }

    /**
     * Test variant deleted / non-existent in cart is detected.
     */
    public function test_non_existent_variant_in_cart_is_rejected(): void
    {
        $product = $this->createProductWithStock(10);

        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => 999999,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'is_valid' => false,
                'has_changes' => true,
            ]);

        $item = $response->json('items.0');
        $this->assertEquals('variant_not_found', $item['status']);
        $this->assertFalse($item['is_valid']);
    }

    /**
     * Test variant belonging to another product is rejected as mismatch.
     */
    public function test_variant_product_mismatch_is_rejected(): void
    {
        $product1 = $this->createProductWithStock(10);
        $product2 = $this->createProductWithStock(10);
        $variant2 = $this->createVariantWithStock($product2, 5);

        // Client presents product1 ID with variant2 ID
        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                [
                    'product_id' => $product1->id,
                    'variant_id' => $variant2->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'is_valid' => false,
                'has_changes' => true,
            ]);

        $item = $response->json('items.0');
        $this->assertEquals('variant_mismatch', $item['status']);
        $this->assertFalse($item['is_valid']);
    }

    /**
     * Test out of stock item (stock = 0) is flagged.
     */
    public function test_out_of_stock_item_is_flagged(): void
    {
        $product = $this->createProductWithStock(0);

        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => null,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'is_valid' => false,
                'has_changes' => true,
                'summary' => [
                    'has_out_of_stock' => true,
                ],
            ]);

        $item = $response->json('items.0');
        $this->assertEquals('out_of_stock', $item['status']);
        $this->assertFalse($item['is_valid']);
        $this->assertEquals(0, $item['available_stock']);
    }

    /**
     * Test stock decreased: cart quantity is automatically normalized to available stock.
     */
    public function test_stock_decreased_adjusts_cart_quantity(): void
    {
        // Product has only 2 in stock, but customer requested 5 in localStorage
        $product = $this->createProductWithStock(2, 40.00);

        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => null,
                    'quantity' => 5,
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'has_changes' => true,
                'summary' => [
                    'total_items' => 2,
                    'subtotal' => 80.00,
                ],
            ]);

        $item = $response->json('items.0');
        $this->assertEquals('quantity_adjusted', $item['status']);
        $this->assertEquals(5, $item['requested_quantity']);
        $this->assertEquals(2, $item['available_stock']);
        $this->assertEquals(2, $item['normalized_quantity']);
    }

    /**
     * Test price change in catalog is detected and authoritative rate returned.
     */
    public function test_catalog_price_change_is_detected(): void
    {
        // Live product price is $80.00
        $product = $this->createProductWithStock(10, 80.00);

        // Stale client cached price was $50.00
        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => null,
                    'quantity' => 1,
                    'price' => 50.00,
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'has_changes' => true,
                'summary' => [
                    'has_price_changes' => true,
                    'subtotal' => 80.00,
                ],
            ]);

        $item = $response->json('items.0');
        $this->assertEquals('price_changed', $item['status']);
        $this->assertTrue($item['price_changed']);
        $this->assertEquals(80.00, $item['unit_price']);
    }

    /**
     * Test empty cart returns valid structure with zero totals.
     */
    public function test_empty_cart_validation_returns_clean_zero_summary(): void
    {
        $response = $this->postJson('/api/cart/validate', [
            'items' => [],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'is_valid' => true,
                'has_changes' => false,
                'items' => [],
                'summary' => [
                    'total_items' => 0,
                    'subtotal' => 0.00,
                    'has_out_of_stock' => false,
                    'has_price_changes' => false,
                    'has_inactive_items' => false,
                    'notices' => [],
                ],
            ]);
    }

    /**
     * Test variant stock reduction caps cart quantity to variant stock.
     */
    public function test_variant_stock_reduction_caps_cart_quantity(): void
    {
        $product = $this->createProductWithStock(50, 100.00);
        $variant = $this->createVariantWithStock($product, 3, 20.00);

        // Client has 6 in cart, but variant only has 3 in stock
        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 6,
                    'price' => 120.00,
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'has_changes' => true,
                'summary' => [
                    'total_items' => 3,
                    'subtotal' => 360.00,
                ],
            ]);

        $item = $response->json('items.0');
        $this->assertEquals('quantity_adjusted', $item['status']);
        $this->assertEquals(6, $item['requested_quantity']);
        $this->assertEquals(3, $item['available_stock']);
        $this->assertEquals(3, $item['normalized_quantity']);
    }

    /**
     * Test variant out of stock is detected.
     */
    public function test_variant_out_of_stock_is_flagged(): void
    {
        $product = $this->createProductWithStock(50, 100.00);
        $variant = $this->createVariantWithStock($product, 0, 20.00);

        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 2,
                    'price' => 120.00,
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'is_valid' => false,
                'has_changes' => true,
                'summary' => [
                    'has_out_of_stock' => true,
                ],
            ]);

        $item = $response->json('items.0');
        $this->assertEquals('out_of_stock', $item['status']);
        $this->assertFalse($item['is_valid']);
        $this->assertEquals(0, $item['available_stock']);
    }

    /**
     * Test variant price modifier change is detected.
     */
    public function test_variant_price_modifier_change_detected(): void
    {
        $product = $this->createProductWithStock(50, 100.00);
        $variant = $this->createVariantWithStock($product, 10, 30.00); // 100 + 30 = 130.00

        // Client has cached price 120.00
        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 1,
                    'price' => 120.00,
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'has_changes' => true,
                'summary' => [
                    'has_price_changes' => true,
                    'subtotal' => 130.00,
                ],
            ]);

        $item = $response->json('items.0');
        $this->assertEquals('price_changed', $item['status']);
        $this->assertTrue($item['price_changed']);
        $this->assertEquals(130.00, $item['unit_price']);
    }
}
