<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicProductCatalogTest extends TestCase
{
    use DatabaseTransactions;

    private Category $category;
    private Brand $brand;
    private User $admin;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::firstOrCreate(
            ['slug' => 'test-catalog-category'],
            [
                'name' => 'Test Catalog Category',
                'description' => 'Category created for public catalog tests',
                'is_featured' => true,
                'display_order' => 1,
            ]
        );

        $this->brand = Brand::firstOrCreate(
            ['slug' => 'test-catalog-brand'],
            [
                'name' => 'Test Catalog Brand',
                'description' => 'Brand created for public catalog tests',
                'is_featured' => true,
                'display_order' => 1,
            ]
        );

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_catalog_tester@example.com'],
            [
                'name' => 'Admin Catalog Tester',
                'password' => bcrypt('secret123'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        $this->customer = User::firstOrCreate(
            ['email' => 'customer_catalog_tester@example.com'],
            [
                'name' => 'Customer Catalog Tester',
                'password' => bcrypt('secret123'),
                'role' => 'customer',
                'status' => 'active',
            ]
        );
    }

    private function createCatalogProduct(array $overrides = []): Product
    {
        $unique = Str::random(8);
        $product = Product::create(array_merge([
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id,
            'name' => 'Public Test Product ' . $unique,
            'slug' => 'public-test-product-' . strtolower($unique),
            'brand' => $this->brand->name,
            'sku' => 'TEST-PUB-' . strtoupper($unique),
            'short_description' => 'Short summary for public product.',
            'description' => 'Complete detailed description for public test product.',
            'price' => 120.00,
            'cost_price' => 65.50, // Sensitive cost field that MUST NEVER leak
            'compare_at_price' => 150.00,
            'stock_quantity' => 25,
            'is_featured' => true,
            'is_new_arrival' => true,
            'is_best_seller' => false,
            'is_active' => true,
            'rating_average' => 4.8,
            'review_count' => 12,
            'tags' => ['featured', 'mechanical', 'rgb'],
            'specifications' => ['Material' => 'Aluminium', 'Connectivity' => 'Wireless'],
        ], $overrides));

        // Attach primary image
        ProductImage::create([
            'product_id' => $product->id,
            'image_url' => 'https://example.com/test-image-primary.jpg',
            'alt_text' => 'Primary Alt Text',
            'is_primary' => true,
            'display_order' => 1,
        ]);

        // Attach variant with sensitive cost_price
        ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Matte Black / Standard',
            'size' => 'Standard',
            'color_name' => 'Matte Black',
            'color_hex' => '#000000',
            'sku' => 'VAR-' . $product->sku . '-BLK',
            'barcode' => '8800' . rand(1000, 9999),
            'cost_price' => 45.00, // Sensitive variant cost that MUST NEVER leak
            'price_modifier' => 10.00,
            'stock_quantity' => 15,
        ]);

        return $product;
    }

    /**
     * Test public product list returns allowlisted fields and strictly omits cost_price.
     */
    public function test_public_product_list_returns_allowlisted_fields_and_no_cost_price(): void
    {
        $product = $this->createCatalogProduct();

        $response = $this->getJson('/api/products');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'category_id',
                        'name',
                        'slug',
                        'sku',
                        'short_description',
                        'description',
                        'price',
                        'compare_at_price',
                        'stock_quantity',
                        'is_featured',
                        'is_active',
                        'rating_average',
                        'review_count',
                        'created_at',
                        'primary_image',
                        'images',
                        'variants',
                    ],
                ],
                'current_page',
                'last_page',
                'per_page',
                'total',
            ]);

        // Explicit security assertion: cost_price must not exist anywhere in the payload
        $response->assertJsonMissing(['cost_price']);
        $rawJson = $response->getContent();
        $this->assertStringNotContainsString('cost_price', $rawJson, 'Public /api/products response must not contain cost_price');
        $this->assertStringNotContainsString('cost_layers', $rawJson);
        $this->assertStringNotContainsString('costLayers', $rawJson);
    }

    /**
     * Test pagination and sorting on public product list.
     */
    public function test_public_product_list_pagination_and_sorting(): void
    {
        $p1 = $this->createCatalogProduct(['price' => 50.00]);
        $p2 = $this->createCatalogProduct(['price' => 200.00]);

        // Test pagination limit
        $res = $this->getJson('/api/products?per_page=5&page=1');
        $res->assertStatus(200);
        $this->assertEquals(5, $res->json('per_page'));
        $this->assertEquals(1, $res->json('current_page'));

        // Test sorting by price asc
        $resAsc = $this->getJson('/api/products?sort=price_asc&per_page=50');
        $resAsc->assertStatus(200);
        $data = $resAsc->json('data');
        if (count($data) >= 2) {
            $this->assertLessThanOrEqual($data[1]['price'], $data[0]['price']);
        }

        // Test sorting by price desc
        $resDesc = $this->getJson('/api/products?sort=price_desc&per_page=50');
        $resDesc->assertStatus(200);
        $dataDesc = $resDesc->json('data');
        if (count($dataDesc) >= 2) {
            $this->assertGreaterThanOrEqual($dataDesc[1]['price'], $dataDesc[0]['price']);
        }
    }

    /**
     * Test category and brand filtering on public product list.
     */
    public function test_public_product_list_filters_by_category_and_brand(): void
    {
        $product = $this->createCatalogProduct();

        // Filter by category slug
        $catRes = $this->getJson('/api/products?category=' . $this->category->slug);
        $catRes->assertStatus(200);
        $this->assertTrue(
            collect($catRes->json('data'))->contains('id', $product->id),
            'Product should be present in its category filter'
        );

        // Filter by brand slug
        $brandRes = $this->getJson('/api/products?brand=' . $this->brand->slug);
        $brandRes->assertStatus(200);
        $this->assertTrue(
            collect($brandRes->json('data'))->contains('id', $product->id),
            'Product should be present in its brand filter'
        );
    }

    /**
     * Test product SKU search (exact and partial).
     */
    public function test_product_sku_search_exact_and_partial(): void
    {
        $uniqueSku = 'SKUTEST-' . Str::upper(Str::random(6));
        $product = $this->createCatalogProduct(['sku' => $uniqueSku]);

        // Exact product SKU search
        $resExact = $this->getJson('/api/products?search=' . urlencode($uniqueSku));
        $resExact->assertStatus(200);
        $this->assertTrue(
            collect($resExact->json('data'))->contains('id', $product->id),
            'Exact product SKU search must return the matching product'
        );

        // Partial product SKU search
        $partialSku = substr($uniqueSku, 0, 7);
        $resPartial = $this->getJson('/api/products?search=' . urlencode($partialSku));
        $resPartial->assertStatus(200);
        $this->assertTrue(
            collect($resPartial->json('data'))->contains('id', $product->id),
            'Partial product SKU search must return the matching product'
        );
    }

    /**
     * Test variant SKU search (exact and partial).
     */
    public function test_variant_sku_search_exact_and_partial(): void
    {
        $uniqueVarSku = 'VARSKU-' . Str::upper(Str::random(6));
        $product = $this->createCatalogProduct();
        
        // Add a variant with this unique SKU
        ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Custom Switch Variant',
            'sku' => $uniqueVarSku,
            'price_modifier' => 5.00,
            'stock_quantity' => 10,
            'cost_price' => 15.00,
        ]);

        // Exact variant SKU search
        $resExact = $this->getJson('/api/products?search=' . urlencode($uniqueVarSku));
        $resExact->assertStatus(200);
        $this->assertTrue(
            collect($resExact->json('data'))->contains('id', $product->id),
            'Searching for a variant SKU must return the parent product'
        );

        // Partial variant SKU search
        $partialVarSku = substr($uniqueVarSku, 0, 7);
        $resPartial = $this->getJson('/api/products?search=' . urlencode($partialVarSku));
        $resPartial->assertStatus(200);
        $this->assertTrue(
            collect($resPartial->json('data'))->contains('id', $product->id),
            'Partial variant SKU search must return the parent product'
        );
    }

    /**
     * Test inactive products are excluded from public catalog.
     */
    public function test_inactive_products_are_excluded_from_public_catalog(): void
    {
        $inactiveProduct = $this->createCatalogProduct(['is_active' => false]);

        $res = $this->getJson('/api/products?search=' . urlencode($inactiveProduct->name));
        $res->assertStatus(200);
        $this->assertFalse(
            collect($res->json('data'))->contains('id', $inactiveProduct->id),
            'Inactive product must never appear in public catalog results'
        );
    }

    /**
     * Test product detail endpoint returns allowlisted fields and strictly omits cost_price.
     */
    public function test_public_product_detail_returns_allowlisted_fields_and_no_cost_price(): void
    {
        $product = $this->createCatalogProduct();

        $res = $this->getJson('/api/products/' . $product->slug);

        $res->assertStatus(200)
            ->assertJsonStructure([
                'product' => [
                    'id',
                    'name',
                    'slug',
                    'sku',
                    'short_description',
                    'description',
                    'price',
                    'compare_at_price',
                    'stock_quantity',
                    'is_featured',
                    'is_active',
                    'rating_average',
                    'review_count',
                    'tags',
                    'specifications',
                    'created_at',
                    'primary_image',
                    'images',
                    'variants' => [
                        '*' => [
                            'id',
                            'product_id',
                            'name',
                            'size',
                            'color_name',
                            'color_hex',
                            'sku',
                            'barcode',
                            'price_modifier',
                            'stock_quantity',
                        ],
                    ],
                ],
                'related',
            ]);

        // Assert cost_price is absent everywhere
        $res->assertJsonMissing(['cost_price']);
        $rawJson = $res->getContent();
        $this->assertStringNotContainsString('cost_price', $rawJson, 'Public product detail must not leak cost_price');
        $this->assertEquals($product->id, $res->json('product.id'));
        $this->assertEquals($product->name, $res->json('product.name'));
        $this->assertNotEmpty($res->json('product.variants'));
    }

    /**
     * Test featured products endpoint returns allowlisted fields and no cost_price.
     */
    public function test_featured_endpoint_returns_clean_resources_without_cost_price(): void
    {
        $this->createCatalogProduct(['is_featured' => true]);

        // Clear cache before test
        \Illuminate\Support\Facades\Cache::forget('api_storefront_featured_payload');

        $res = $this->getJson('/api/featured');

        $res->assertStatus(200)
            ->assertJsonStructure([
                'featured_products',
                'new_arrivals',
                'best_sellers',
                'featured_categories',
            ]);

        // Explicit security assertion: cost_price must not be in featured payload
        $res->assertJsonMissing(['cost_price']);
        $rawJson = $res->getContent();
        $this->assertStringNotContainsString('cost_price', $rawJson, 'Featured payload must not leak cost_price');
    }

    /**
     * Test authorization / access check: public catalog remains safe regardless of auth status.
     */
    public function test_authorization_matrix_for_public_catalog(): void
    {
        $product = $this->createCatalogProduct();

        // 1. Unauthenticated visitor
        $resGuest = $this->getJson('/api/products/' . $product->slug);
        $resGuest->assertStatus(200);
        $this->assertStringNotContainsString('cost_price', $resGuest->getContent());

        // 2. Authenticated customer
        Sanctum::actingAs($this->customer, ['customer:access']);
        $resCust = $this->getJson('/api/products/' . $product->slug);
        $resCust->assertStatus(200);
        $this->assertStringNotContainsString('cost_price', $resCust->getContent());

        // 3. Authenticated admin accessing public storefront endpoint
        Sanctum::actingAs($this->admin, ['admin:access']);
        $resAdmin = $this->getJson('/api/products/' . $product->slug);
        $resAdmin->assertStatus(200);
        // Even when an admin calls public endpoint, cost_price MUST NOT leak to public endpoint payload
        $this->assertStringNotContainsString('cost_price', $resAdmin->getContent());
    }

    /**
     * Test that newly added internal model attributes or relations are never serialized to public API.
     */
    public function test_dynamic_internal_attributes_added_to_model_are_not_serialized_to_public_api(): void
    {
        $product = $this->createCatalogProduct();
        $product->load(['variants']);

        // Dynamically inject sensitive/internal attributes onto the Eloquent models
        $product->internal_margin_percentage = 42.5;
        $product->internal_audit_classified = 'SECRET_TOKEN_DO_NOT_EXPOSE';
        $product->warehouse_bin_location = 'AISLE-4-BIN-2';

        if ($product->variants->isNotEmpty()) {
            $variant = $product->variants->first();
            $variant->vendor_accounting_code = 'VENDOR-ACC-888';
            $variant->fifo_cost_batch = 'BATCH-2026-X';
        }

        $resource = (new \App\Http\Resources\ProductResource($product))->response()->getData(true);

        // Assert strictly allowlisted fields only
        $this->assertArrayNotHasKey('cost_price', $resource);
        $this->assertArrayNotHasKey('internal_margin_percentage', $resource);
        $this->assertArrayNotHasKey('internal_audit_classified', $resource);
        $this->assertArrayNotHasKey('warehouse_bin_location', $resource);

        if (!empty($resource['variants'])) {
            $vResource = $resource['variants'][0];
            $this->assertArrayNotHasKey('cost_price', $vResource);
            $this->assertArrayNotHasKey('vendor_accounting_code', $vResource);
            $this->assertArrayNotHasKey('fifo_cost_batch', $vResource);
        }
    }
}
