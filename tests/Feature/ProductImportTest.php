<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ProductImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductImportTest extends TestCase
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
            ['email' => 'admin_import_tester@example.com'],
            [
                'name' => 'Admin Import Tester',
                'password' => bcrypt('secret123'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        $this->customer = User::firstOrCreate(
            ['email' => 'customer_import_tester@example.com'],
            [
                'name' => 'Customer Tester',
                'password' => bcrypt('secret123'),
                'role' => 'customer',
                'status' => 'active',
            ]
        );

        $this->categoryA = Category::firstOrCreate(
            ['slug' => 'import-test-cat-a'],
            ['name' => 'Import Cat A', 'description' => 'Category A for import tests']
        );

        $this->categoryB = Category::firstOrCreate(
            ['slug' => 'import-test-cat-b'],
            ['name' => 'Import Cat B', 'description' => 'Category B for import tests']
        );

        $this->brandA = Brand::firstOrCreate(
            ['slug' => 'import-test-brand-a'],
            ['name' => 'Import Brand A']
        );

        $this->brandB = Brand::firstOrCreate(
            ['slug' => 'import-test-brand-b'],
            ['name' => 'Import Brand B']
        );
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);
    }

    private function actAsCustomer(): void
    {
        Sanctum::actingAs($this->customer);
    }

    /**
     * Helper to build a valid CSV row string according to Phase 3I-B export columns.
     */
    private function buildCsvContent(array $headers, array $rows): string
    {
        $fp = fopen('php://memory', 'r+');
        fputcsv($fp, $headers);
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        rewind($fp);
        $content = stream_get_contents($fp);
        fclose($fp);
        return $content;
    }

    private function getStandardHeaders(): array
    {
        return [
            'Product ID',
            'SKU',
            'Name',
            'Slug',
            'Description',
            'Short Description',
            'Price',
            'Compare At Price',
            'Cost Price',
            'Category',
            'Category Slug',
            'Brand',
            'Brand Slug',
            'Stock Quantity',
            'Is Active',
            'Is Featured',
            'Is New Arrival',
            'Is Best Seller',
            'Variant Summary',
            'Primary Image URL',
            'Additional Image URLs',
        ];
    }

    private function createUploadedFile(string $content, string $filename = 'products.csv', string $mimeType = 'text/csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($filename, $content);
    }

    // 1. Authorized admin can upload CSV for dry-run validation
    public function test_1_authorized_admin_can_upload_csv_for_validation(): void
    {
        $this->actAsAdmin();

        $sku = 'TEST-IMP-' . strtoupper(Str::random(6));
        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', $sku, 'Imported Product 1', 'imp-prod-1', 'Description text', 'Short desc', '49.99', '59.99', '25.00', $this->categoryA->name, $this->categoryA->slug, $this->brandA->name, $this->brandA->slug, '0', 'true', 'false', 'false', 'false', '', '', '']
        ]);

        $file = $this->createUploadedFile($csv);
        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);

        $res->assertOk()
            ->assertJsonPath('can_commit', true)
            ->assertJsonPath('summary.total_rows', 1)
            ->assertJsonPath('summary.creates', 1)
            ->assertJsonPath('summary.errors', 0);

        $this->assertNotEmpty($res->json('import_token'));
    }

    // 2. Unauthorized user cannot import
    public function test_2_unauthorized_user_cannot_import(): void
    {
        $csv = $this->buildCsvContent($this->getStandardHeaders(), []);
        $file = $this->createUploadedFile($csv);

        // Guest
        $resGuest = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $resGuest->assertUnauthorized();

        // Customer
        $this->actAsCustomer();
        $resCustomer = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $resCustomer->assertForbidden();
    }

    // 3. Invalid file type rejected
    public function test_3_invalid_file_type_rejected(): void
    {
        $this->actAsAdmin();

        $file = UploadedFile::fake()->createWithContent('malicious.exe', 'binarycontent');
        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);

        $res->assertStatus(422)
            ->assertJsonFragment(['status' => 'error']);
    }

    // 4. Oversized file rejected
    public function test_4_oversized_file_rejected(): void
    {
        $this->actAsAdmin();

        // Over 10MB limit
        $file = UploadedFile::fake()->create('large.csv', 11000); // 11 MB
        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);

        $res->assertStatus(422);
    }

    // 5. Invalid CSV rejected
    public function test_5_invalid_csv_rejected(): void
    {
        $this->actAsAdmin();

        $file = UploadedFile::fake()->createWithContent('empty.csv', '');
        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);

        $res->assertStatus(422);
    }

    // 6. Missing required header rejected
    public function test_6_missing_required_header_rejected(): void
    {
        $this->actAsAdmin();

        $headers = ['Product ID', 'Name', 'Price']; // missing SKU, Category
        $csv = $this->buildCsvContent($headers, [
            ['', 'Product X', '10.00']
        ]);
        $file = $this->createUploadedFile($csv);

        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $res->assertStatus(422)
            ->assertSee('Missing required column');
    }

    // 7. Duplicate header rejected
    public function test_7_duplicate_header_rejected(): void
    {
        $this->actAsAdmin();

        $csv = "SKU,Name,Price,Category,SKU\n1,A,10,C,1\n";
        $file = $this->createUploadedFile($csv);

        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $res->assertStatus(422)
            ->assertSee('Duplicate column header detected');
    }

    // 8. Duplicate SKU inside CSV rejected
    public function test_8_duplicate_sku_inside_csv_rejected(): void
    {
        $this->actAsAdmin();

        $sku = 'DUP-SKU-001';
        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', $sku, 'Prod 1', 'prod-1', 'Desc', 'Short', '20.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', ''],
            ['', $sku, 'Prod 2', 'prod-2', 'Desc', 'Short', '25.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', ''],
        ]);
        $file = $this->createUploadedFile($csv);

        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $res->assertOk()
            ->assertJsonPath('can_commit', false)
            ->assertJsonPath('summary.errors', 1);

        $this->assertStringContainsString('Duplicate SKU', $res->json('rows.1.errors.0'));
    }

    // 9. Existing SKU correctly classified as UPDATE
    public function test_9_existing_sku_correctly_classified_as_update(): void
    {
        $this->actAsAdmin();

        $existing = Product::create([
            'name' => 'Existing Product',
            'sku' => 'EXIST-SKU-99',
            'slug' => 'existing-prod-99',
            'category_id' => $this->categoryA->id,
            'price' => 50.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            [(string)$existing->id, $existing->sku, 'Updated Product Name', $existing->slug, 'New desc', '', '55.00', '', '', $this->categoryA->name, '', '', '', '10', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $res->assertOk()
            ->assertJsonPath('summary.updates', 1)
            ->assertJsonPath('summary.creates', 0)
            ->assertJsonPath('rows.0.action', 'UPDATE')
            ->assertJsonPath('rows.0.changes.name.to', 'Updated Product Name')
            ->assertJsonPath('rows.0.changes.price.to', '55.00');
    }

    // 10. Unknown SKU correctly classified as CREATE
    public function test_10_unknown_sku_correctly_classified_as_create(): void
    {
        $this->actAsAdmin();

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', 'NEW-SKU-100', 'New Brand Product', 'new-brand-prod', 'Desc', '', '75.00', '', '', $this->categoryB->name, '', $this->brandB->name, '', '0', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $res->assertOk()
            ->assertJsonPath('summary.creates', 1)
            ->assertJsonPath('rows.0.action', 'CREATE');
    }

    // 11. Missing category rejected
    public function test_11_missing_category_rejected(): void
    {
        $this->actAsAdmin();

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', 'MISSING-CAT-SKU', 'Prod Cat Missing', 'pcm', '', '', '10.00', '', '', 'Non-Existent Category 999', '', '', '', '0', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $res->assertOk()
            ->assertJsonPath('can_commit', false)
            ->assertJsonPath('rows.0.action', 'ERROR');
        $this->assertStringContainsString('Category "Non-Existent Category 999" was not found', $res->json('rows.0.errors.0'));
    }

    // 12. Missing brand rejected
    public function test_12_missing_brand_rejected(): void
    {
        $this->actAsAdmin();

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', 'MISSING-BRAND-SKU', 'Prod Brand Missing', 'pbm', '', '', '10.00', '', '', $this->categoryA->name, '', 'Non-Existent Brand 999', '', '0', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $res->assertOk()
            ->assertJsonPath('can_commit', false)
            ->assertJsonPath('rows.0.action', 'ERROR');
        $this->assertStringContainsString('Brand "Non-Existent Brand 999" was not found', $res->json('rows.0.errors.0'));
    }

    // 13. Invalid price rejected
    public function test_13_invalid_price_rejected(): void
    {
        $this->actAsAdmin();

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', 'BAD-PRICE-SKU', 'Prod Bad Price', 'pbp', '', '', 'invalid_price', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $res->assertOk()
            ->assertJsonPath('can_commit', false)
            ->assertJsonPath('rows.0.action', 'ERROR');
        $this->assertStringContainsString('Price must be a valid non-negative number', $res->json('rows.0.errors.0'));
    }

    // 14. Negative price rejected
    public function test_14_negative_price_rejected(): void
    {
        $this->actAsAdmin();

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', 'NEG-PRICE-SKU', 'Prod Neg Price', 'pnp', '', '', '-15.50', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $res->assertOk()
            ->assertJsonPath('can_commit', false)
            ->assertJsonPath('rows.0.action', 'ERROR');
        $this->assertStringContainsString('Price must be a valid non-negative number', $res->json('rows.0.errors.0'));
    }

    // 15. Invalid boolean rejected
    public function test_15_invalid_boolean_rejected(): void
    {
        $this->actAsAdmin();

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', 'BAD-BOOL-SKU', 'Prod Bad Bool', 'pbb', '', '', '20.00', '', '', $this->categoryA->name, '', '', '', '0', 'maybe', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $res->assertOk()
            ->assertJsonPath('can_commit', false)
            ->assertJsonPath('rows.0.action', 'ERROR');
        $this->assertStringContainsString('Invalid boolean value', $res->json('rows.0.errors.0'));
    }

    // 16. Dry-run does not modify products
    public function test_16_dry_run_does_not_modify_products(): void
    {
        $this->actAsAdmin();

        $existing = Product::create([
            'name' => 'Original Name',
            'sku' => 'DRY-RUN-SKU-1',
            'slug' => 'dry-run-1',
            'category_id' => $this->categoryA->id,
            'price' => 30.00,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $initialProductCount = Product::count();

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            [(string)$existing->id, $existing->sku, 'Altered Name', $existing->slug, '', '', '999.00', '', '', $this->categoryA->name, '', '', '', '50', 'false', 'false', 'false', 'false', '', '', ''],
            ['', 'BRAND-NEW-SKU', 'Brand New Product', 'bnp', '', '', '10.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $res->assertOk();

        // Verify NO changes happened in DB
        $this->assertEquals($initialProductCount, Product::count());
        $fresh = $existing->fresh();
        $this->assertEquals('Original Name', $fresh->name);
        $this->assertEquals(30.00, (float)$fresh->price);
        $this->assertTrue((bool)$fresh->is_active);
    }

    // 17. Dry-run does not modify variants
    public function test_17_dry_run_does_not_modify_variants(): void
    {
        $this->actAsAdmin();

        $product = Product::create([
            'name' => 'Parent With Variant',
            'sku' => 'PAR-VAR-DRY',
            'slug' => 'par-var-dry',
            'category_id' => $this->categoryA->id,
            'price' => 40.00,
            'stock_quantity' => 10,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'VAR-DRY-1',
            'name' => 'Small Red',
            'size' => 'S',
            'color_name' => 'Red',
            'stock_quantity' => 10,
            'price_modifier' => 0.00,
        ]);

        $initialVariantCount = ProductVariant::count();

        $variantJson = json_encode([[
            'id' => $variant->id,
            'sku' => $variant->sku,
            'name' => 'Mutated Variant Name',
            'size' => 'M',
            'color_name' => 'Blue',
            'stock_quantity' => 999,
        ]]);

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            [(string)$product->id, $product->sku, $product->name, $product->slug, '', '', '40.00', '', '', $this->categoryA->name, '', '', '', '10', 'true', 'false', 'false', 'false', $variantJson, '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $res->assertOk();

        $this->assertEquals($initialVariantCount, ProductVariant::count());
        $freshVariant = $variant->fresh();
        $this->assertEquals('Small Red', $freshVariant->name);
        $this->assertEquals('S', $freshVariant->size);
        $this->assertEquals('Red', $freshVariant->color_name);
        $this->assertEquals(10, $freshVariant->stock_quantity);
    }

    // 18. Dry-run does not modify inventory
    public function test_18_dry_run_does_not_modify_inventory(): void
    {
        $this->actAsAdmin();

        $initialMovementCount = InventoryMovement::count();

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', 'INV-CHECK-SKU', 'Inventory Test Product', 'inv-tp', '', '', '25.00', '', '', $this->categoryA->name, '', '', '', '100', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $this->postJson('/api/admin/products/import/validate', ['file' => $file])->assertOk();

        $this->assertEquals($initialMovementCount, InventoryMovement::count());
    }

    // 19. Dry-run does not modify FIFO cost layers
    public function test_19_dry_run_does_not_modify_fifo_cost_layers(): void
    {
        $this->actAsAdmin();

        $initialCostLayersCount = InventoryCostLayer::count();

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', 'FIFO-CHECK-SKU', 'FIFO Test Product', 'fifo-tp', '', '', '25.00', '', '15.00', $this->categoryA->name, '', '', '', '50', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $this->postJson('/api/admin/products/import/validate', ['file' => $file])->assertOk();

        $this->assertEquals($initialCostLayersCount, InventoryCostLayer::count());
    }

    // 20. Dry-run produces correct CREATE/UPDATE/UNCHANGED counts
    public function test_20_dry_run_produces_correct_counts(): void
    {
        $this->actAsAdmin();

        $p1 = Product::create([
            'name' => 'Prod Unchanged',
            'sku' => 'COUNT-SKU-UNCHANGED',
            'slug' => 'count-sku-unchanged',
            'category_id' => $this->categoryA->id,
            'price' => 50.00,
            'is_active' => true,
        ]);

        $p2 = Product::create([
            'name' => 'Prod To Update',
            'sku' => 'COUNT-SKU-UPDATE',
            'slug' => 'count-sku-update',
            'category_id' => $this->categoryA->id,
            'price' => 30.00,
            'is_active' => true,
        ]);

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            // Row 1: Unchanged
            [(string)$p1->id, $p1->sku, $p1->name, $p1->slug, '', '', '50.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', ''],
            // Row 2: Update
            [(string)$p2->id, $p2->sku, 'New Name for P2', $p2->slug, '', '', '35.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', ''],
            // Row 3: Create
            ['', 'COUNT-SKU-CREATE', 'Brand New Item', 'brand-new-item', '', '', '22.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', ''],
        ]);
        $file = $this->createUploadedFile($csv);

        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $res->assertOk()
            ->assertJsonPath('summary.total_rows', 3)
            ->assertJsonPath('summary.unchanged', 1)
            ->assertJsonPath('summary.updates', 1)
            ->assertJsonPath('summary.creates', 1)
            ->assertJsonPath('summary.errors', 0);
    }

    // 21. Dry-run produces row-level diffs
    public function test_21_dry_run_produces_row_level_diffs(): void
    {
        $this->actAsAdmin();

        $prod = Product::create([
            'name' => 'Old Title',
            'sku' => 'DIFF-SKU-01',
            'slug' => 'diff-sku-01',
            'category_id' => $this->categoryA->id,
            'brand_id' => $this->brandA->id,
            'brand' => $this->brandA->name,
            'price' => 10.00,
            'is_active' => false,
        ]);

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            [(string)$prod->id, $prod->sku, 'New Title', $prod->slug, '', '', '15.00', '', '', $this->categoryA->name, '', $this->brandB->name, '', '0', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $res->assertOk();

        $changes = $res->json('rows.0.changes');
        $this->assertEquals('Old Title', $changes['name']['from']);
        $this->assertEquals('New Title', $changes['name']['to']);
        $this->assertEquals('10.00', $changes['price']['from']);
        $this->assertEquals('15.00', $changes['price']['to']);
        $this->assertEquals('false', $changes['is_active']['from']);
        $this->assertEquals('true', $changes['is_active']['to']);
        $this->assertEquals($this->brandA->name, $changes['brand']['from']);
        $this->assertEquals($this->brandB->name, $changes['brand']['to']);
    }

    // 22. Commit creates valid products
    public function test_22_commit_creates_valid_products(): void
    {
        $this->actAsAdmin();

        $sku = 'COMMIT-CREATE-01';
        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', $sku, 'Brand New Product', 'brand-new-prod-01', 'Detailed desc', 'Short desc', '88.50', '99.00', '40.00', $this->categoryA->name, '', $this->brandA->name, '', '0', 'true', 'true', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $valRes = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $valRes->assertOk();
        $token = $valRes->json('import_token');

        $commitRes = $this->postJson('/api/admin/products/import/commit', ['import_token' => $token]);
        $commitRes->assertOk()
            ->assertJsonPath('products_created', 1)
            ->assertJsonPath('products_updated', 0);

        $created = Product::where('sku', $sku)->first();
        $this->assertNotNull($created);
        $this->assertEquals('Brand New Product', $created->name);
        $this->assertEquals(88.50, (float)$created->price);
        $this->assertEquals(99.00, (float)$created->compare_at_price);
        $this->assertEquals(40.00, (float)$created->cost_price);
        $this->assertTrue((bool)$created->is_active);
        $this->assertTrue((bool)$created->is_featured);
    }

    // 23. Commit updates valid products
    public function test_23_commit_updates_valid_products(): void
    {
        $this->actAsAdmin();

        $prod = Product::create([
            'name' => 'Pre-Update Name',
            'sku' => 'COMMIT-UPDATE-01',
            'slug' => 'commit-update-01',
            'category_id' => $this->categoryA->id,
            'price' => 45.00,
            'is_active' => true,
        ]);

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            [(string)$prod->id, $prod->sku, 'Post-Update Name', $prod->slug, 'Updated desc', '', '49.00', '', '', $this->categoryB->name, '', '', '', '0', 'false', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $valRes = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $token = $valRes->json('import_token');

        $commitRes = $this->postJson('/api/admin/products/import/commit', ['import_token' => $token]);
        $commitRes->assertOk()
            ->assertJsonPath('products_updated', 1);

        $fresh = $prod->fresh();
        $this->assertEquals('Post-Update Name', $fresh->name);
        $this->assertEquals(49.00, (float)$fresh->price);
        $this->assertEquals($this->categoryB->id, $fresh->category_id);
        $this->assertFalse((bool)$fresh->is_active);
    }

    // 24. Commit preserves existing product IDs
    public function test_24_commit_preserves_existing_product_ids(): void
    {
        $this->actAsAdmin();

        $prod = Product::create([
            'name' => 'ID Preservation Product',
            'sku' => 'PRESERVE-ID-SKU',
            'slug' => 'preserve-id-sku',
            'category_id' => $this->categoryA->id,
            'price' => 20.00,
        ]);
        $originalId = $prod->id;

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            [(string)$originalId, $prod->sku, 'ID Preserved Name', $prod->slug, '', '', '22.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $token = $this->postJson('/api/admin/products/import/validate', ['file' => $file])->json('import_token');
        $this->postJson('/api/admin/products/import/commit', ['import_token' => $token])->assertOk();

        $fresh = Product::where('sku', 'PRESERVE-ID-SKU')->first();
        $this->assertEquals($originalId, $fresh->id);
    }

    // 25. Commit preserves existing variant IDs where applicable
    public function test_25_commit_preserves_existing_variant_ids_where_applicable(): void
    {
        $this->actAsAdmin();

        $prod = Product::create([
            'name' => 'Parent Preserved Variants',
            'sku' => 'PARENT-VAR-PRES',
            'slug' => 'parent-var-pres',
            'category_id' => $this->categoryA->id,
            'price' => 50.00,
        ]);

        $var1 = ProductVariant::create([
            'product_id' => $prod->id,
            'sku' => 'VAR-PRES-01',
            'name' => 'Size M / Navy',
            'size' => 'M',
            'color_name' => 'Navy',
            'stock_quantity' => 5,
        ]);

        $originalVarId = $var1->id;

        $variantJson = json_encode([
            [
                'id' => $originalVarId,
                'sku' => 'VAR-PRES-01',
                'name' => 'Size M / Navy Blue',
                'size' => 'M',
                'color_name' => 'Navy',
                'stock_quantity' => 5,
                'price_modifier' => 2.50,
            ]
        ]);

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            [(string)$prod->id, $prod->sku, $prod->name, $prod->slug, '', '', '50.00', '', '', $this->categoryA->name, '', '', '', '5', 'true', 'false', 'false', 'false', $variantJson, '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $token = $this->postJson('/api/admin/products/import/validate', ['file' => $file])->json('import_token');
        $res = $this->postJson('/api/admin/products/import/commit', ['import_token' => $token]);
        $res->assertOk();

        $updatedVariant = ProductVariant::where('sku', 'VAR-PRES-01')->first();
        $this->assertNotNull($updatedVariant);
        $this->assertEquals($originalVarId, $updatedVariant->id);
        $this->assertEquals('Size M / Navy Blue', $updatedVariant->name);
        $this->assertEquals(2.50, (float)$updatedVariant->price_modifier);
    }

    // 26. Commit does not break order_items.variant_id references
    public function test_26_commit_does_not_break_order_items_variant_id_references(): void
    {
        $this->actAsAdmin();

        $prod = Product::create([
            'name' => 'Order Item Reference Product',
            'sku' => 'OIR-PROD-SKU',
            'slug' => 'oir-prod-sku',
            'category_id' => $this->categoryA->id,
            'price' => 70.00,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $prod->id,
            'sku' => 'OIR-VAR-SKU',
            'name' => 'Large White',
            'size' => 'L',
            'color_name' => 'White',
            'stock_quantity' => 12,
        ]);

        $order = Order::create([
            'order_number' => 'ORD-IMPORT-REF-' . strtoupper(Str::random(4)),
            'user_id' => $this->customer->id,
            'customer_name' => 'Customer Tester',
            'customer_email' => 'customer_tester@example.com',
            'shipping_address' => '123 Main St, Dhaka',
            'subtotal' => 70.00,
            'total_amount' => 70.00,
            'order_status' => 'completed',
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $prod->id,
            'variant_id' => $variant->id,
            'product_name' => $prod->name,
            'variant_name' => $variant->name,
            'product_sku' => $variant->sku,
            'unit_price' => 70.00,
            'quantity' => 1,
            'total_price' => 70.00,
        ]);

        $variantJson = json_encode([
            [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'name' => 'Large Pearl White',
                'size' => 'L',
                'color_name' => 'White',
                'stock_quantity' => 12,
            ]
        ]);

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            [(string)$prod->id, $prod->sku, $prod->name, $prod->slug, '', '', '70.00', '', '', $this->categoryA->name, '', '', '', '12', 'true', 'false', 'false', 'false', $variantJson, '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $token = $this->postJson('/api/admin/products/import/validate', ['file' => $file])->json('import_token');
        $this->postJson('/api/admin/products/import/commit', ['import_token' => $token])->assertOk();

        // Check foreign key reference remains completely intact
        $freshItem = $orderItem->fresh();
        $this->assertEquals($variant->id, $freshItem->variant_id);
        $this->assertNotNull($freshItem->variant);
    }

    // 27. Duplicate variant combinations rejected
    public function test_27_duplicate_variant_combinations_rejected(): void
    {
        $this->actAsAdmin();

        $variantJson = json_encode([
            ['sku' => 'V-DUP-01', 'name' => 'Red S 1', 'size' => 'S', 'color_name' => 'Red'],
            ['sku' => 'V-DUP-02', 'name' => 'Red S 2', 'size' => 'S', 'color_name' => 'Red'], // duplicate combination!
        ]);

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', 'VAR-DUP-COMB-SKU', 'Prod Dup Comb', 'prod-dup-comb', '', '', '30.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', $variantJson, '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $res->assertOk()
            ->assertJsonPath('can_commit', false);
        $this->assertStringContainsString('Duplicate variant combination', $res->json('rows.0.errors.0'));
    }

    // 28. Duplicate variant SKUs rejected
    public function test_28_duplicate_variant_skus_rejected(): void
    {
        $this->actAsAdmin();

        $variantJson = json_encode([
            ['sku' => 'SHARED-VAR-SKU', 'name' => 'Red S', 'size' => 'S', 'color_name' => 'Red'],
            ['sku' => 'SHARED-VAR-SKU', 'name' => 'Blue M', 'size' => 'M', 'color_name' => 'Blue'], // duplicate SKU!
        ]);

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', 'VAR-DUP-SKU-SKU', 'Prod Dup Var SKU', 'prod-dup-var-sku', '', '', '30.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', $variantJson, '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $res->assertOk()
            ->assertJsonPath('can_commit', false);
        $this->assertStringContainsString('Duplicate variant SKU', $res->json('rows.0.errors.0'));
    }

    // 29. Invalid import causes zero database changes
    public function test_29_invalid_import_causes_zero_database_changes(): void
    {
        $this->actAsAdmin();

        $initialProductCount = Product::count();

        // 1 valid row, 1 invalid row (missing category)
        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', 'VALID-ROW-SKU', 'Valid Row', 'valid-row', '', '', '15.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', ''],
            ['', 'INVALID-ROW-SKU', 'Invalid Row', 'invalid-row', '', '', '15.00', '', '', 'Unknown Category 404', '', '', '', '0', 'true', 'false', 'false', 'false', '', '', ''],
        ]);
        $file = $this->createUploadedFile($csv);

        $valRes = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $valRes->assertOk()->assertJsonPath('can_commit', false);
        $token = $valRes->json('import_token');

        // Trying to commit an invalid import token must fail with 422
        $commitRes = $this->postJson('/api/admin/products/import/commit', ['import_token' => $token]);
        $commitRes->assertStatus(422)
            ->assertSee('Cannot commit import');

        // Verify valid row was NOT created
        $this->assertEquals($initialProductCount, Product::count());
        $this->assertNull(Product::where('sku', 'VALID-ROW-SKU')->first());
    }

    // 30. Unexpected commit failure rolls back the entire transaction
    public function test_30_unexpected_commit_failure_rolls_back_the_entire_transaction(): void
    {
        $this->actAsAdmin();

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', 'ROLLBACK-SKU-1', 'Product Rollback 1', 'pr-1', '', '', '10.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', ''],
            ['', 'ROLLBACK-SKU-2', 'Product Rollback 2', 'pr-2', '', '', '20.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', ''],
        ]);
        $file = $this->createUploadedFile($csv);

        $valRes = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $token = $valRes->json('import_token');

        // Hook into Product::saving to simulate an unexpected exception during commit
        Product::saving(function ($product) {
            if ($product->sku === 'ROLLBACK-SKU-2') {
                throw new \RuntimeException("Simulated failure during commit transaction");
            }
        });

        $commitRes = $this->postJson('/api/admin/products/import/commit', ['import_token' => $token]);
        $commitRes->assertStatus(422)
            ->assertSee('Simulated failure during commit transaction');

        // Verify ROLLBACK-SKU-1 was rolled back and NOT committed
        $this->assertNull(Product::where('sku', 'ROLLBACK-SKU-1')->first());
    }

    // 31. Revalidation occurs during commit
    public function test_31_revalidation_occurs_during_commit(): void
    {
        $this->actAsAdmin();

        $sku = 'REVAL-SKU-01';
        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', $sku, 'Product Reval', 'prod-reval', '', '', '35.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $token = $this->postJson('/api/admin/products/import/validate', ['file' => $file])->json('import_token');

        // Delete/rename category before commit, which will invalidate the file
        $catName = $this->categoryA->name;
        $this->categoryA->update(['name' => 'Renamed Before Commit', 'slug' => 'renamed-before-commit']);

        $res = $this->postJson('/api/admin/products/import/commit', ['import_token' => $token]);
        $res->assertStatus(422);
        $this->assertStringContainsString("Category \"{$catName}\" was not found", $res->json('message'));

        $this->assertNull(Product::where('sku', $sku)->first());
    }

    // 32. Stale product data is detected safely
    public function test_32_stale_product_data_is_detected_safely(): void
    {
        $this->actAsAdmin();

        $prod = Product::create([
            'name' => 'Stale Product Test',
            'sku' => 'STALE-SKU-01',
            'slug' => 'stale-sku-01',
            'category_id' => $this->categoryA->id,
            'price' => 20.00,
        ]);

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            [(string)$prod->id, $prod->sku, 'Stale Product Updated', $prod->slug, '', '', '25.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $token = $this->postJson('/api/admin/products/import/validate', ['file' => $file])->json('import_token');

        // Now another admin edits the product
        sleep(1);
        $prod->update(['name' => 'Modified By Another Admin']);

        // Now trying to commit should detect stale record
        $res = $this->postJson('/api/admin/products/import/commit', ['import_token' => $token]);
        $res->assertStatus(422)
            ->assertSee('has been modified by another administrator');
    }

    // 33. Temporary import files are cleaned up
    public function test_33_temporary_import_files_are_cleaned_up(): void
    {
        $this->actAsAdmin();

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', 'TEMP-CLEANUP-SKU', 'Temp Cleanup Prod', 'temp-clean-p', '', '', '15.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $token = $this->postJson('/api/admin/products/import/validate', ['file' => $file])->json('import_token');
        $storedFilePath = ProductImportService::getImportStorageDir() . DIRECTORY_SEPARATOR . $token . '.csv';

        // File should exist right after dry-run
        $this->assertFileExists($storedFilePath);

        // Commit import
        $this->postJson('/api/admin/products/import/commit', ['import_token' => $token])->assertOk();

        // File should be deleted after successful commit
        $this->assertFileDoesNotExist($storedFilePath);
    }

    // 34. Audit log is written for successful commit
    public function test_34_audit_log_is_written_for_successful_commit(): void
    {
        $this->actAsAdmin();

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', 'AUDIT-LOG-SKU', 'Audit Log Prod', 'audit-log-prod', '', '', '15.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $token = $this->postJson('/api/admin/products/import/validate', ['file' => $file])->json('import_token');
        $this->postJson('/api/admin/products/import/commit', ['import_token' => $token])->assertOk();

        $log = AuditLog::where('action', 'product_import')
            ->where('user_id', $this->admin->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('Product', $log->entity_type);
        $this->assertEquals('completed', $log->new_values['commit_result']);
        $this->assertEquals(1, $log->new_values['creates']);
    }

    // 35. Audit log or error result is correct for failed commit
    public function test_35_audit_result_is_correct_for_failed_commit(): void
    {
        $this->actAsAdmin();

        $invalidToken = str_repeat('f', 40);
        $res = $this->postJson('/api/admin/products/import/commit', ['import_token' => $invalidToken]);
        $res->assertStatus(422)
            ->assertSee('Import session has expired or the file was not found');
    }

    // 36. CSV fields containing commas work
    public function test_36_csv_fields_containing_commas_work(): void
    {
        $this->actAsAdmin();

        $desc = "This is a detailed, comma-separated, high-quality description, featuring red, blue, and green options.";
        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', 'COMMA-SKU-01', 'Comma Product', 'comma-prod', $desc, 'Short, with, commas', '20.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $token = $this->postJson('/api/admin/products/import/validate', ['file' => $file])->json('import_token');
        $this->postJson('/api/admin/products/import/commit', ['import_token' => $token])->assertOk();

        $created = Product::where('sku', 'COMMA-SKU-01')->first();
        $this->assertEquals($desc, $created->description);
    }

    // 37. CSV fields containing quotes work
    public function test_37_csv_fields_containing_quotes_work(): void
    {
        $this->actAsAdmin();

        $name = 'Product with "Special Quotes" & 15" Display';
        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', 'QUOTE-SKU-01', $name, 'quote-prod', 'Description', '', '99.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $token = $this->postJson('/api/admin/products/import/validate', ['file' => $file])->json('import_token');
        $this->postJson('/api/admin/products/import/commit', ['import_token' => $token])->assertOk();

        $created = Product::where('sku', 'QUOTE-SKU-01')->first();
        $this->assertEquals($name, $created->name);
    }

    // 38. CSV fields containing newlines work
    public function test_38_csv_fields_containing_newlines_work(): void
    {
        $this->actAsAdmin();

        $multilineDesc = "Line 1: High quality.\nLine 2: Durable material.\r\nLine 3: 1 Year Warranty.";
        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', 'NEWLINE-SKU-01', 'Newline Product', 'newline-prod', $multilineDesc, '', '40.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $token = $this->postJson('/api/admin/products/import/validate', ['file' => $file])->json('import_token');
        $this->postJson('/api/admin/products/import/commit', ['import_token' => $token])->assertOk();

        $created = Product::where('sku', 'NEWLINE-SKU-01')->first();
        $this->assertEquals($multilineDesc, $created->description);
    }

    // 39. Unicode works
    public function test_39_unicode_works(): void
    {
        $this->actAsAdmin();

        $unicodeName = "Premium 智能手表 🌟 (Élégant Edition)";
        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', 'UNICODE-SKU-01', $unicodeName, 'unicode-prod', 'عالي الجودة', '', '150.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $token = $this->postJson('/api/admin/products/import/validate', ['file' => $file])->json('import_token');
        $this->postJson('/api/admin/products/import/commit', ['import_token' => $token])->assertOk();

        $created = Product::where('sku', 'UNICODE-SKU-01')->first();
        $this->assertEquals($unicodeName, $created->name);
        $this->assertEquals('عالي الجودة', $created->description);
    }

    // 40. UTF-8 BOM works
    public function test_40_utf8_bom_works(): void
    {
        $this->actAsAdmin();

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', 'BOM-SKU-01', 'BOM Product', 'bom-prod', 'Desc', '', '18.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $bom = chr(0xEF) . chr(0xBB) . chr(0xBF);
        $bomCsv = $bom . $csv;

        $file = $this->createUploadedFile($bomCsv);
        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);

        $res->assertOk()
            ->assertJsonPath('can_commit', true)
            ->assertJsonPath('summary.creates', 1);
    }

    // 41. Formula-injection-safe values are handled consistently with Phase 3I-B
    public function test_41_formula_injection_safe_values_handled_consistently(): void
    {
        $this->actAsAdmin();

        // In Phase 3I-B, values starting with =, +, -, @ were prefixed with a single quote '
        $formulaTitle = "'+ Special Edition Formula";
        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            ['', 'FORMULA-SKU-01', $formulaTitle, 'formula-prod', "'=SUM(1,2)", '', '50.00', '', '', $this->categoryA->name, '', '', '', '0', 'true', 'false', 'false', 'false', '', '', '']
        ]);
        $file = $this->createUploadedFile($csv);

        $token = $this->postJson('/api/admin/products/import/validate', ['file' => $file])->json('import_token');
        $this->postJson('/api/admin/products/import/commit', ['import_token' => $token])->assertOk();

        $created = Product::where('sku', 'FORMULA-SKU-01')->first();
        $this->assertEquals('+ Special Edition Formula', $created->name);
        $this->assertEquals('=SUM(1,2)', $created->description);
    }
}
