<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductExportTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $customer;
    private Category $categoryA;
    private Category $categoryB;
    private Brand $brandA;
    private Brand $brandB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_export_tester@example.com'],
            [
                'name' => 'Admin Export Tester',
                'password' => bcrypt('secret123'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        $this->customer = User::firstOrCreate(
            ['email' => 'customer_export_tester@example.com'],
            [
                'name' => 'Customer Tester',
                'password' => bcrypt('secret123'),
                'role' => 'customer',
                'status' => 'active',
            ]
        );

        $this->categoryA = Category::firstOrCreate(
            ['slug' => 'test-export-cat-a'],
            ['name' => 'Export Cat A', 'description' => 'Category A for export tests']
        );

        $this->categoryB = Category::firstOrCreate(
            ['slug' => 'test-export-cat-b'],
            ['name' => 'Export Cat B', 'description' => 'Category B for export tests']
        );

        $this->brandA = Brand::firstOrCreate(
            ['slug' => 'test-export-brand-a'],
            ['name' => 'Export Brand Alpha']
        );

        $this->brandB = Brand::firstOrCreate(
            ['slug' => 'test-export-brand-b'],
            ['name' => 'Export Brand Beta']
        );
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);
    }

    private function createProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Export Product ' . Str::random(5),
            'sku' => 'EXP-' . Str::upper(Str::random(6)),
            'slug' => 'export-prod-' . Str::random(8),
            'category_id' => $this->categoryA->id,
            'brand_id' => $this->brandA->id,
            'brand' => $this->brandA->name,
            'price' => 99.99,
            'cost_price' => 45.00,
            'compare_at_price' => 129.99,
            'stock_quantity' => 15,
            'description' => 'Full product description for export testing.',
            'short_description' => 'Short product description.',
            'is_active' => true,
            'is_featured' => false,
            'is_new_arrival' => false,
            'is_best_seller' => false,
        ], $overrides));
    }

    /**
     * Parse streamed CSV string into rows array handling BOM and multi-line cells.
     */
    private function parseCsv(string $content): array
    {
        $bom = chr(0xEF) . chr(0xBB) . chr(0xBF);
        if (str_starts_with($content, $bom)) {
            $content = substr($content, strlen($bom));
        }

        $lines = [];
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        while (($row = fgetcsv($stream)) !== false) {
            $lines[] = $row;
        }
        fclose($stream);
        return $lines;
    }

    // 1. Authorized admin can export products
    public function test_1_authorized_admin_can_export_products(): void
    {
        $this->actAsAdmin();
        $this->createProduct();

        $response = $this->get('/api/admin/products/export');
        $response->assertOk();
    }

    // 2. Unauthorized/non-admin cannot export products
    public function test_2_unauthorized_user_cannot_export_products(): void
    {
        // Unauthenticated
        $guestResponse = $this->getJson('/api/admin/products/export');
        $guestResponse->assertUnauthorized();

        // Customer role
        Sanctum::actingAs($this->customer, ['customer:access']);
        $custResponse = $this->getJson('/api/admin/products/export');
        $custResponse->assertForbidden();
    }

    // 3. Export returns CSV content type
    public function test_3_export_returns_csv_content_type(): void
    {
        $this->actAsAdmin();
        $response = $this->get('/api/admin/products/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    // 4. Export has correct Content-Disposition filename
    public function test_4_export_has_correct_content_disposition_filename(): void
    {
        $this->actAsAdmin();
        $response = $this->get('/api/admin/products/export');

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertNotNull($disposition);
        $this->assertMatchesRegularExpression('/attachment;\s*filename="?products-export-\d{4}-\d{2}-\d{2}-\d{2}-\d{2}\.csv"?/i', $disposition);
    }

    // 5. Export includes CSV headers
    public function test_5_export_includes_csv_headers(): void
    {
        $this->actAsAdmin();
        $response = $this->get('/api/admin/products/export');

        $rows = $this->parseCsv($response->streamedContent());
        $this->assertNotEmpty($rows);

        $expectedHeaders = [
            'ID',
            'SKU',
            'Name',
            'Slug',
            'Description',
            'Short Description',
            'Category',
            'Brand',
            'Price',
            'Compare At Price',
            'Cost Price',
            'Stock Quantity',
            'Is Active',
            'Is Featured',
            'Is New Arrival',
            'Is Best Seller',
            'Primary Image URL',
            'Additional Image URLs',
            'Variant Summary',
            'Created At',
            'Updated At',
        ];

        $this->assertEquals($expectedHeaders, $rows[0]);
    }

    // 6. Export includes product data
    public function test_6_export_includes_product_data(): void
    {
        $this->actAsAdmin();
        $prod = $this->createProduct([
            'name' => 'Specific Test Audio Headset',
            'sku' => 'HEADSET-999',
            'slug' => 'specific-test-audio-headset',
            'description' => 'Detailed acoustics description',
            'short_description' => 'Short acoustics blurb',
        ]);

        $response = $this->get('/api/admin/products/export?search=HEADSET-999');
        $rows = $this->parseCsv($response->streamedContent());

        $this->assertCount(2, $rows); // header + 1 product
        $row = $rows[1];
        $this->assertEquals($prod->id, (int) $row[0]);
        $this->assertEquals('HEADSET-999', $row[1]);
        $this->assertEquals('Specific Test Audio Headset', $row[2]);
        $this->assertEquals('specific-test-audio-headset', $row[3]);
        $this->assertEquals('Detailed acoustics description', $row[4]);
        $this->assertEquals('Short acoustics blurb', $row[5]);
    }

    // 7. Export includes category and brand correctly
    public function test_7_export_includes_category_and_brand_correctly(): void
    {
        $this->actAsAdmin();
        $prod = $this->createProduct([
            'category_id' => $this->categoryB->id,
            'brand_id' => $this->brandB->id,
            'brand' => $this->brandB->name,
            'sku' => 'CATBRAND-01',
        ]);

        $response = $this->get('/api/admin/products/export?search=CATBRAND-01');
        $rows = $this->parseCsv($response->streamedContent());

        $this->assertCount(2, $rows);
        $row = $rows[1];
        $this->assertEquals($this->categoryB->name, $row[6]); // Category
        $this->assertEquals($this->brandB->name, $row[7]);    // Brand
    }

    // 8. Export includes pricing fields correctly
    public function test_8_export_includes_pricing_fields_correctly(): void
    {
        $this->actAsAdmin();
        $prod = $this->createProduct([
            'sku' => 'PRICE-CHECK-88',
            'price' => 149.50,
            'compare_at_price' => 199.90,
            'cost_price' => 85.25,
        ]);

        $response = $this->get('/api/admin/products/export?search=PRICE-CHECK-88');
        $rows = $this->parseCsv($response->streamedContent());

        $this->assertCount(2, $rows);
        $row = $rows[1];
        $this->assertEquals('149.50', $row[8]);  // Price
        $this->assertEquals('199.90', $row[9]);  // Compare At Price
        $this->assertEquals('85.25', $row[10]);  // Cost Price
    }

    // 9. Export includes stock correctly
    public function test_9_export_includes_stock_correctly(): void
    {
        $this->actAsAdmin();
        $prod = $this->createProduct([
            'sku' => 'STOCK-CHECK-77',
            'stock_quantity' => 42,
        ]);

        $response = $this->get('/api/admin/products/export?search=STOCK-CHECK-77');
        $rows = $this->parseCsv($response->streamedContent());

        $this->assertCount(2, $rows);
        $row = $rows[1];
        $this->assertEquals('42', $row[11]); // Stock Quantity
    }

    // 10. Export includes primary and additional media correctly
    public function test_10_export_includes_primary_and_additional_media_correctly(): void
    {
        $this->actAsAdmin();
        $prod = $this->createProduct(['sku' => 'MEDIA-CHECK-10']);

        // Primary image
        ProductImage::create([
            'product_id' => $prod->id,
            'image_url' => 'https://example.com/images/primary.jpg',
            'alt_text' => 'Primary Alt',
            'color_name' => 'Midnight',
            'is_primary' => true,
            'display_order' => 0,
        ]);

        // Additional image
        ProductImage::create([
            'product_id' => $prod->id,
            'image_url' => 'https://example.com/images/extra.jpg',
            'alt_text' => 'Extra Alt',
            'color_name' => 'Midnight',
            'is_primary' => false,
            'display_order' => 1,
        ]);

        $response = $this->get('/api/admin/products/export?search=MEDIA-CHECK-10');
        $rows = $this->parseCsv($response->streamedContent());

        $this->assertCount(2, $rows);
        $row = $rows[1];
        $this->assertEquals('https://example.com/images/primary.jpg', $row[16]); // Primary Image URL

        $additionalJson = $row[17]; // Additional Image URLs
        $this->assertNotEmpty($additionalJson);
        $decoded = json_decode($additionalJson, true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertEquals('https://example.com/images/extra.jpg', $decoded[0]['url']);
        $this->assertEquals('Midnight', $decoded[0]['color_name']);
        $this->assertEquals('Extra Alt', $decoded[0]['alt_text']);
        $this->assertEquals(1, $decoded[0]['display_order']);
        $this->assertFalse($decoded[0]['is_primary']);
    }

    // 11. Export includes variant summary correctly
    public function test_11_export_includes_variant_summary_correctly(): void
    {
        $this->actAsAdmin();
        $prod = $this->createProduct(['sku' => 'VAR-SUMM-11']);

        ProductVariant::create([
            'product_id' => $prod->id,
            'sku' => 'VAR-SUMM-11-RED',
            'name' => 'Red / L',
            'color_name' => 'Red',
            'color_hex' => '#ff0000',
            'size' => 'L',
            'price_modifier' => 10.00,
            'cost_price' => 35.00,
            'stock_quantity' => 8,
            'barcode' => '7891011121314',
        ]);

        $response = $this->get('/api/admin/products/export?search=VAR-SUMM-11');
        $rows = $this->parseCsv($response->streamedContent());

        $this->assertCount(2, $rows);
        $row = $rows[1];
        $variantJson = $row[18]; // Variant Summary
        $this->assertNotEmpty($variantJson);

        $decoded = json_decode($variantJson, true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertEquals('VAR-SUMM-11-RED', $decoded[0]['sku']);
        $this->assertEquals('Red / L', $decoded[0]['name']);
        $this->assertEquals('Red', $decoded[0]['color_name']);
        $this->assertEquals('#ff0000', $decoded[0]['color_hex']);
        $this->assertEquals('L', $decoded[0]['size']);
        $this->assertEquals(10.0, (float) $decoded[0]['price_modifier']);
        $this->assertEquals(35.0, (float) $decoded[0]['cost_price']);
        $this->assertEquals(8, (int) $decoded[0]['stock_quantity']);
        $this->assertEquals('7891011121314', $decoded[0]['barcode']);
    }

    // 12. Products without variants export correctly
    public function test_12_products_without_variants_export_correctly(): void
    {
        $this->actAsAdmin();
        $prod = $this->createProduct(['sku' => 'NO-VAR-12']);

        $response = $this->get('/api/admin/products/export?search=NO-VAR-12');
        $rows = $this->parseCsv($response->streamedContent());

        $this->assertCount(2, $rows);
        $this->assertEquals('', $rows[1][18]); // Empty variant summary cell
    }

    // 13. Products with generic variants export correctly
    public function test_13_products_with_generic_variants_export_correctly(): void
    {
        $this->actAsAdmin();
        $prod = $this->createProduct(['sku' => 'GEN-VAR-13']);

        ProductVariant::create([
            'product_id' => $prod->id,
            'sku' => 'GEN-VAR-13-ED1',
            'name' => 'Standard Edition',
            'color_name' => null,
            'color_hex' => null,
            'size' => null,
            'price_modifier' => 0.00,
            'cost_price' => 30.00,
            'stock_quantity' => 12,
            'barcode' => null,
        ]);

        $response = $this->get('/api/admin/products/export?search=GEN-VAR-13');
        $rows = $this->parseCsv($response->streamedContent());

        $this->assertCount(2, $rows);
        $decoded = json_decode($rows[1][18], true);
        $this->assertCount(1, $decoded);
        $this->assertEquals('Standard Edition', $decoded[0]['name']);
        $this->assertNull($decoded[0]['color_name']);
        $this->assertNull($decoded[0]['size']);
    }

    // 14. Products with color/size variants export correctly
    public function test_14_products_with_color_size_variants_export_correctly(): void
    {
        $this->actAsAdmin();
        $prod = $this->createProduct(['sku' => 'COLOR-SIZE-14']);

        ProductVariant::create([
            'product_id' => $prod->id,
            'sku' => 'COLOR-SIZE-14-BL-S',
            'name' => 'Blue / S',
            'color_name' => 'Blue',
            'color_hex' => '#0000ff',
            'size' => 'S',
            'price_modifier' => 0.00,
            'cost_price' => 20.00,
            'stock_quantity' => 5,
        ]);
        ProductVariant::create([
            'product_id' => $prod->id,
            'sku' => 'COLOR-SIZE-14-BL-M',
            'name' => 'Blue / M',
            'color_name' => 'Blue',
            'color_hex' => '#0000ff',
            'size' => 'M',
            'price_modifier' => 2.00,
            'cost_price' => 22.00,
            'stock_quantity' => 7,
        ]);

        $response = $this->get('/api/admin/products/export?search=COLOR-SIZE-14');
        $rows = $this->parseCsv($response->streamedContent());

        $this->assertCount(2, $rows);
        $decoded = json_decode($rows[1][18], true);
        $this->assertCount(2, $decoded);
        $this->assertEquals('Blue', $decoded[0]['color_name']);
        $this->assertEquals('S', $decoded[0]['size']);
        $this->assertEquals('Blue', $decoded[1]['color_name']);
        $this->assertEquals('M', $decoded[1]['size']);
    }

    // 15. Category filter works
    public function test_15_category_filter_works(): void
    {
        $this->actAsAdmin();
        $p1 = $this->createProduct(['sku' => 'CATFILT-A', 'category_id' => $this->categoryA->id]);
        $p2 = $this->createProduct(['sku' => 'CATFILT-B', 'category_id' => $this->categoryB->id]);

        $response = $this->get('/api/admin/products/export?category_id=' . $this->categoryB->id);
        $rows = $this->parseCsv($response->streamedContent());

        $exportedSkus = array_column(array_slice($rows, 1), 1);
        $this->assertContains('CATFILT-B', $exportedSkus);
        $this->assertNotContains('CATFILT-A', $exportedSkus);
    }

    // 16. Brand filter works
    public function test_16_brand_filter_works(): void
    {
        $this->actAsAdmin();
        $p1 = $this->createProduct(['sku' => 'BRANDFILT-A', 'brand_id' => $this->brandA->id]);
        $p2 = $this->createProduct(['sku' => 'BRANDFILT-B', 'brand_id' => $this->brandB->id]);

        $response = $this->get('/api/admin/products/export?brand_id=' . $this->brandB->id);
        $rows = $this->parseCsv($response->streamedContent());

        $exportedSkus = array_column(array_slice($rows, 1), 1);
        $this->assertContains('BRANDFILT-B', $exportedSkus);
        $this->assertNotContains('BRANDFILT-A', $exportedSkus);
    }

    // 17. Status filter works
    public function test_17_status_filter_works(): void
    {
        $this->actAsAdmin();
        $pActive = $this->createProduct(['sku' => 'STATUS-ACT-17', 'is_active' => true]);
        $pInactive = $this->createProduct(['sku' => 'STATUS-INACT-17', 'is_active' => false]);

        $responseActive = $this->get('/api/admin/products/export?status=active&search=STATUS-');
        $rowsActive = $this->parseCsv($responseActive->streamedContent());
        $activeSkus = array_column(array_slice($rowsActive, 1), 1);
        $this->assertContains('STATUS-ACT-17', $activeSkus);
        $this->assertNotContains('STATUS-INACT-17', $activeSkus);

        $responseInactive = $this->get('/api/admin/products/export?status=inactive&search=STATUS-');
        $rowsInactive = $this->parseCsv($responseInactive->streamedContent());
        $inactiveSkus = array_column(array_slice($rowsInactive, 1), 1);
        $this->assertContains('STATUS-INACT-17', $inactiveSkus);
        $this->assertNotContains('STATUS-ACT-17', $inactiveSkus);
    }

    // 18. Search filter works
    public function test_18_search_filter_works(): void
    {
        $this->actAsAdmin();
        $p1 = $this->createProduct(['sku' => 'SEARCH-OMEGA-XYZ', 'name' => 'Omega Special Widget']);
        $p2 = $this->createProduct(['sku' => 'SEARCH-BETA-ABC', 'name' => 'Beta Regular Gadget']);

        $response = $this->get('/api/admin/products/export?search=OMEGA');
        $rows = $this->parseCsv($response->streamedContent());

        $exportedSkus = array_column(array_slice($rows, 1), 1);
        $this->assertContains('SEARCH-OMEGA-XYZ', $exportedSkus);
        $this->assertNotContains('SEARCH-BETA-ABC', $exportedSkus);
    }

    // 19. Stock filter works
    public function test_19_stock_filter_works(): void
    {
        $this->actAsAdmin();
        $pLow = $this->createProduct(['sku' => 'STOCK-LOW-19', 'stock_quantity' => 4]);
        $pOut = $this->createProduct(['sku' => 'STOCK-OUT-19', 'stock_quantity' => 0]);
        $pHigh = $this->createProduct(['sku' => 'STOCK-HIGH-19', 'stock_quantity' => 50]);

        // Low stock (1 <= stock <= 10)
        $resLow = $this->get('/api/admin/products/export?stock=low&search=STOCK-');
        $rowsLow = $this->parseCsv($resLow->streamedContent());
        $lowSkus = array_column(array_slice($rowsLow, 1), 1);
        $this->assertContains('STOCK-LOW-19', $lowSkus);
        $this->assertNotContains('STOCK-OUT-19', $lowSkus);
        $this->assertNotContains('STOCK-HIGH-19', $lowSkus);

        // Out of stock (stock <= 0)
        $resOut = $this->get('/api/admin/products/export?stock_status=out_of_stock&search=STOCK-');
        $rowsOut = $this->parseCsv($resOut->streamedContent());
        $outSkus = array_column(array_slice($rowsOut, 1), 1);
        $this->assertContains('STOCK-OUT-19', $outSkus);
        $this->assertNotContains('STOCK-LOW-19', $outSkus);
    }

    // 20. Combined filters work
    public function test_20_combined_filters_work(): void
    {
        $this->actAsAdmin();
        $match = $this->createProduct([
            'sku' => 'COMBO-MATCH-20',
            'name' => 'Target Combo Device',
            'category_id' => $this->categoryA->id,
            'brand_id' => $this->brandA->id,
            'is_active' => true,
            'stock_quantity' => 5,
        ]);

        $nonMatchBrand = $this->createProduct([
            'sku' => 'COMBO-DIFF-BRAND',
            'name' => 'Target Combo Device Other Brand',
            'category_id' => $this->categoryA->id,
            'brand_id' => $this->brandB->id,
            'is_active' => true,
            'stock_quantity' => 5,
        ]);

        $response = $this->get('/api/admin/products/export?' . http_build_query([
            'search' => 'Target Combo',
            'category_id' => $this->categoryA->id,
            'brand_id' => $this->brandA->id,
            'status' => 'active',
            'stock' => 'low',
        ]));

        $rows = $this->parseCsv($response->streamedContent());
        $skus = array_column(array_slice($rows, 1), 1);
        $this->assertContains('COMBO-MATCH-20', $skus);
        $this->assertNotContains('COMBO-DIFF-BRAND', $skus);
    }

    // 21. CSV escaping works for commas, quotes, newlines, Unicode, and formula injection protection
    public function test_21_csv_escaping_and_formula_safety(): void
    {
        $this->actAsAdmin();
        $p = $this->createProduct([
            'sku' => '=SUM(A1:A10)', // formula injection attempt
            'name' => 'Product with "quotes", commas, and 日本語/বাংলা',
            'short_description' => '-cmd| /C calc', // formula trigger at start of text
            'description' => "Line 1 with text\nLine 2 with commas, and \"inner quotes\"",
        ]);

        $response = $this->get('/api/admin/products/export?search=quotes');
        $rawStream = $response->streamedContent();

        // Verify UTF-8 BOM is present
        $bom = chr(0xEF) . chr(0xBB) . chr(0xBF);
        $this->assertTrue(str_starts_with($rawStream, $bom));

        $rows = $this->parseCsv($rawStream);
        $this->assertCount(2, $rows);

        $row = $rows[1];
        // Formula injection neutralized with leading single quote:
        $this->assertEquals("'=SUM(A1:A10)", $row[1]);
        // Special characters and Unicode preserved:
        $this->assertEquals('Product with "quotes", commas, and 日本語/বাংলা', $row[2]);
        // Short description formula trigger neutralized:
        $this->assertEquals("'-cmd| /C calc", $row[5]);
        // Newlines and quotes inside description parsed cleanly:
        $this->assertEquals("Line 1 with text\nLine 2 with commas, and \"inner quotes\"", $row[4]);
    }

    // 22. Export does not modify database records
    public function test_22_export_does_not_modify_database_records(): void
    {
        $this->actAsAdmin();
        $p = $this->createProduct(['sku' => 'READONLY-22', 'price' => 55.00]);

        $beforeCount = Product::count();
        $beforeUpdated = $p->fresh()->updated_at;

        $response = $this->get('/api/admin/products/export');
        $response->assertOk();

        $afterCount = Product::count();
        $afterUpdated = $p->fresh()->updated_at;

        $this->assertEquals($beforeCount, $afterCount);
        $this->assertEquals($beforeUpdated->toIso8601String(), $afterUpdated->toIso8601String());
        $this->assertEquals(55.00, (float) $p->fresh()->price);
    }

    // 23. Export does not modify inventory records
    public function test_23_export_does_not_modify_inventory_records(): void
    {
        $this->actAsAdmin();
        $beforeMovementsCount = InventoryMovement::count();

        $response = $this->get('/api/admin/products/export');
        $response->assertOk();

        $this->assertEquals($beforeMovementsCount, InventoryMovement::count());
    }

    // 24. Export does not modify orders/returns
    public function test_24_export_does_not_modify_orders_or_returns(): void
    {
        $this->actAsAdmin();
        $beforeOrdersCount = Order::count();
        $beforeReturnsCount = OrderReturn::count();

        $response = $this->get('/api/admin/products/export');
        $response->assertOk();

        $this->assertEquals($beforeOrdersCount, Order::count());
        $this->assertEquals($beforeReturnsCount, OrderReturn::count());
    }

    // 25. Export does not modify FIFO cost layers
    public function test_25_export_does_not_modify_fifo_cost_layers(): void
    {
        $this->actAsAdmin();
        $beforeLayersCount = InventoryCostLayer::count();
        $beforeRemainingQuantity = InventoryCostLayer::sum('remaining_quantity');

        $response = $this->get('/api/admin/products/export');
        $response->assertOk();

        $this->assertEquals($beforeLayersCount, InventoryCostLayer::count());
        $this->assertEquals($beforeRemainingQuantity, InventoryCostLayer::sum('remaining_quantity'));
    }
}
