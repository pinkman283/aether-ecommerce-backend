<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

class ProductMediaLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Category $category;
    private string $pngContent;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_test_media@example.com'],
            [
                'name' => 'Admin Media Test',
                'password' => bcrypt('password123'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        Sanctum::actingAs($this->admin, ['admin:access']);

        $this->category = Category::firstOrCreate(
            ['name' => 'Audio Media Test'],
            ['slug' => 'audio-media-test']
        );

        // 1x1 valid PNG byte stream
        $this->pngContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    }

    private function createFakeUploadedFile(string $filename = 'test.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($filename, $this->pngContent);
    }

    public function test_product_image_can_be_created_and_physical_file_is_stored(): void
    {
        $file = $this->createFakeUploadedFile('speaker.png');
        $uploadRes = $this->postJson('/api/admin/products/upload-image', ['image' => $file]);
        $uploadRes->assertStatus(201);

        $imageUrl = $uploadRes->json('image_url');
        $path = $uploadRes->json('path');

        $this->assertTrue(Storage::disk('public')->exists($path));

        $productRes = $this->postJson('/api/admin/products', [
            'name' => 'Studio Speaker A1',
            'category_id' => $this->category->id,
            'price' => 199.99,
            'stock_quantity' => 10,
            'description' => 'High quality studio monitor.',
            'images' => [
                [
                    'image_url' => $imageUrl,
                    'is_primary' => true,
                    'alt_text' => 'Studio Speaker A1 Front',
                    'color_name' => 'Matte Black',
                ],
            ],
        ]);

        $productRes->assertStatus(201);
        $productId = $productRes->json('product.id');

        $this->assertDatabaseHas('product_images', [
            'product_id' => $productId,
            'alt_text' => 'Studio Speaker A1 Front',
            'color_name' => 'Matte Black',
            'is_primary' => 1,
        ]);
    }

    public function test_product_image_physical_file_is_deleted_when_image_record_is_deleted(): void
    {
        $path = 'products/sample_delete_test_' . time() . '.png';
        Storage::disk('public')->put($path, $this->pngContent);
        $this->assertTrue(Storage::disk('public')->exists($path));

        $product = Product::create([
            'name' => 'Test Cleanup Item',
            'slug' => 'test-cleanup-item-' . time(),
            'category_id' => $this->category->id,
            'price' => 50,
            'stock_quantity' => 5,
            'description' => 'Cleanup testing.',
        ]);

        $image = $product->images()->create([
            'image_url' => url('storage/' . $path),
            'alt_text' => 'Sample Image',
            'is_primary' => true,
            'display_order' => 0,
        ]);

        // Deleting the image model must delete the physical file
        $image->delete();

        $this->assertFalse(Storage::disk('public')->exists($path));
        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
    }

    public function test_updating_a_product_preserves_unchanged_images_and_removes_deleted_ones(): void
    {
        $pathKept = 'products/kept_' . time() . '.png';
        $pathRemoved = 'products/removed_' . time() . '.png';
        Storage::disk('public')->put($pathKept, $this->pngContent);
        Storage::disk('public')->put($pathRemoved, $this->pngContent);

        $product = Product::create([
            'name' => 'Reconcile Test',
            'slug' => 'reconcile-test-' . time(),
            'category_id' => $this->category->id,
            'price' => 75,
            'stock_quantity' => 12,
            'description' => 'Image reconcile testing.',
        ]);

        $img1 = $product->images()->create([
            'image_url' => url('storage/' . $pathKept),
            'alt_text' => 'Kept Image',
            'is_primary' => true,
            'display_order' => 0,
        ]);

        $img2 = $product->images()->create([
            'image_url' => url('storage/' . $pathRemoved),
            'alt_text' => 'Removed Image',
            'is_primary' => false,
            'display_order' => 1,
        ]);

        // Submit update keeping ONLY img1
        $updateRes = $this->putJson("/api/admin/products/{$product->id}", [
            'name' => 'Reconcile Test Updated',
            'price' => 80,
            'images' => [
                [
                    'id' => $img1->id,
                    'image_url' => $img1->image_url,
                    'alt_text' => 'Kept Image Updated',
                    'color_name' => 'Silver',
                    'is_primary' => true,
                    'display_order' => 0,
                ],
            ],
        ]);

        $updateRes->assertStatus(200);

        // Kept file must still exist on disk
        $this->assertTrue(Storage::disk('public')->exists($pathKept));
        // Removed file must have been deleted from disk
        $this->assertFalse(Storage::disk('public')->exists($pathRemoved));

        // Database checks
        $this->assertDatabaseHas('product_images', [
            'id' => $img1->id,
            'alt_text' => 'Kept Image Updated',
            'color_name' => 'Silver',
        ]);
        $this->assertDatabaseMissing('product_images', ['id' => $img2->id]);
    }

    public function test_deleting_a_safe_product_removes_all_its_image_files(): void
    {
        $path1 = 'products/prod_safe1_' . time() . '.png';
        $path2 = 'products/prod_safe2_' . time() . '.png';
        Storage::disk('public')->put($path1, $this->pngContent);
        Storage::disk('public')->put($path2, $this->pngContent);

        $product = Product::create([
            'name' => 'Safe Deletion Product',
            'slug' => 'safe-del-prod-' . time(),
            'category_id' => $this->category->id,
            'price' => 100,
            'stock_quantity' => 10,
            'description' => 'No historical records.',
        ]);

        $product->images()->create([
            'image_url' => url('storage/' . $path1),
            'alt_text' => 'Safe 1',
            'is_primary' => true,
            'display_order' => 0,
        ]);

        $product->images()->create([
            'image_url' => url('storage/' . $path2),
            'alt_text' => 'Safe 2',
            'is_primary' => false,
            'display_order' => 1,
        ]);

        $this->assertTrue(Storage::disk('public')->exists($path1));
        $this->assertTrue(Storage::disk('public')->exists($path2));

        $delRes = $this->deleteJson("/api/admin/products/{$product->id}");
        $delRes->assertStatus(200);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertFalse(Storage::disk('public')->exists($path1));
        $this->assertFalse(Storage::disk('public')->exists($path2));
    }

    public function test_missing_physical_file_does_not_crash_image_or_product_deletion(): void
    {
        $product = Product::create([
            'name' => 'Non Existent File Test',
            'slug' => 'non-existent-file-' . time(),
            'category_id' => $this->category->id,
            'price' => 50,
            'stock_quantity' => 5,
            'description' => 'File missing from storage.',
        ]);

        $image = $product->images()->create([
            'image_url' => url('storage/products/ghost_file_' . time() . '.png'),
            'alt_text' => 'Ghost Image',
            'is_primary' => true,
            'display_order' => 0,
        ]);

        // File does NOT exist on disk
        $this->assertFalse(Storage::disk('public')->exists('products/' . basename($image->image_url)));

        // Deleting should not crash or throw
        $delRes = $this->deleteJson("/api/admin/products/{$product->id}");
        $delRes->assertStatus(200);
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
    }

    public function test_uncommitted_uploaded_image_can_be_deleted_before_save(): void
    {
        $file = $this->createFakeUploadedFile('uncommitted.png');
        $uploadRes = $this->postJson('/api/admin/products/upload-image', ['image' => $file]);
        $uploadRes->assertStatus(201);

        $path = $uploadRes->json('path');
        $this->assertTrue(Storage::disk('public')->exists($path));

        // Delete uncommitted upload
        $deleteRes = $this->postJson('/api/admin/products/delete-uncommitted-image', [
            'path' => $path,
        ]);
        $deleteRes->assertStatus(200);
        $this->assertFalse(Storage::disk('public')->exists($path));
    }

    public function test_uncommitted_deletion_endpoint_refuses_to_delete_active_product_images(): void
    {
        $path = 'products/active_prod_' . time() . '.png';
        Storage::disk('public')->put($path, $this->pngContent);

        $product = Product::create([
            'name' => 'Active Image Item',
            'slug' => 'active-img-item-' . time(),
            'category_id' => $this->category->id,
            'price' => 50,
            'stock_quantity' => 5,
            'description' => 'Test protection.',
        ]);

        $product->images()->create([
            'image_url' => url('storage/' . $path),
            'alt_text' => 'Active',
            'is_primary' => true,
            'display_order' => 0,
        ]);

        $deleteRes = $this->postJson('/api/admin/products/delete-uncommitted-image', [
            'path' => $path,
        ]);
        $deleteRes->assertStatus(422);
        // Physical file must be preserved
        $this->assertTrue(Storage::disk('public')->exists($path));
    }

    public function test_orphan_cleanup_artisan_command_detects_and_deletes_orphans(): void
    {
        $orphanPath = 'products/orphan_' . time() . '.png';
        $referencedPath = 'products/referenced_' . time() . '.png';

        Storage::disk('public')->put($orphanPath, $this->pngContent);
        Storage::disk('public')->put($referencedPath, $this->pngContent);

        $product = Product::create([
            'name' => 'Referenced Prod',
            'slug' => 'referenced-prod-' . time(),
            'category_id' => $this->category->id,
            'price' => 50,
            'stock_quantity' => 5,
            'description' => 'Referenced image.',
        ]);

        $product->images()->create([
            'image_url' => url('storage/' . $referencedPath),
            'alt_text' => 'Referenced',
            'is_primary' => true,
            'display_order' => 0,
        ]);

        // 1. Dry run
        $this->artisan('products:cleanup-orphaned-images', ['--dry-run' => true])
            ->assertSuccessful();

        // Dry run must NOT delete files
        $this->assertTrue(Storage::disk('public')->exists($orphanPath));
        $this->assertTrue(Storage::disk('public')->exists($referencedPath));

        // 2. Explicit delete mode
        $this->artisan('products:cleanup-orphaned-images', ['--delete' => true])
            ->assertSuccessful();

        // Orphan must be deleted, referenced must remain
        $this->assertFalse(Storage::disk('public')->exists($orphanPath));
        $this->assertTrue(Storage::disk('public')->exists($referencedPath));
    }

    public function test_product_deletion_is_blocked_if_order_items_exist(): void
    {
        $product = Product::create([
            'name' => 'Ordered Product',
            'slug' => 'ordered-prod-' . time(),
            'category_id' => $this->category->id,
            'price' => 150,
            'stock_quantity' => 10,
            'description' => 'Product with order history.',
        ]);

        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $this->admin->id,
            'order_number' => 'ORD-TEST-' . time(),
            'customer_name' => 'Test Customer',
            'customer_email' => 'test@example.com',
            'shipping_address' => json_encode(['address' => '123 Test St']),
            'subtotal' => 150.00,
            'total_amount' => 150.00,
            'order_status' => 'delivered',
            'payment_status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => 'SKU-TEST',
            'unit_price' => 150.00,
            'quantity' => 1,
            'total_price' => 150.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $delRes = $this->deleteJson("/api/admin/products/{$product->id}");
        $delRes->assertStatus(422)
            ->assertJsonPath('code', 'PRODUCT_DELETE_BLOCKED')
            ->assertJsonPath('conflicts.order_items_count', 1);

        // Product must still exist in database
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_product_deletion_is_blocked_if_inventory_movements_exist(): void
    {
        $product = Product::create([
            'name' => 'Inventory Moved Product',
            'slug' => 'inv-moved-prod-' . time(),
            'category_id' => $this->category->id,
            'price' => 80,
            'stock_quantity' => 20,
            'description' => 'Has ledger movements.',
        ]);

        DB::table('inventory_movements')->insert([
            'product_id' => $product->id,
            'movement_type' => 'manual_adjustment',
            'quantity' => 20,
            'balance_after' => 20,
            'created_at' => now(),
        ]);

        $delRes = $this->deleteJson("/api/admin/products/{$product->id}");
        $delRes->assertStatus(422)
            ->assertJsonPath('code', 'PRODUCT_DELETE_BLOCKED')
            ->assertJsonPath('conflicts.inventory_movements_count', 1);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_bulk_destroy_protects_historical_products_and_deletes_safe_ones(): void
    {
        $safeProduct = Product::create([
            'name' => 'Bulk Safe Product',
            'slug' => 'bulk-safe-' . time(),
            'category_id' => $this->category->id,
            'price' => 30,
            'stock_quantity' => 5,
            'description' => 'Safe to delete.',
        ]);

        $protectedProduct = Product::create([
            'name' => 'Bulk Protected Product',
            'slug' => 'bulk-protected-' . time(),
            'category_id' => $this->category->id,
            'price' => 45,
            'stock_quantity' => 5,
            'description' => 'Has cost layers.',
        ]);

        DB::table('inventory_cost_layers')->insert([
            'product_id' => $protectedProduct->id,
            'unit_cost' => 20.00,
            'initial_quantity' => 5,
            'remaining_quantity' => 5,
            'is_depleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bulkRes = $this->postJson('/api/admin/products/bulk-delete', [
            'ids' => [$safeProduct->id, $protectedProduct->id],
        ]);

        $bulkRes->assertStatus(200)
            ->assertJsonPath('deleted_count', 1)
            ->assertJsonPath('blocked_count', 1);

        // Safe product was deleted
        $this->assertDatabaseMissing('products', ['id' => $safeProduct->id]);
        // Protected product remains intact
        $this->assertDatabaseHas('products', ['id' => $protectedProduct->id]);
    }

    public function test_product_images_support_color_association_and_fallback_to_general(): void
    {
        $product = Product::create([
            'name' => 'Color Image Headphone',
            'slug' => 'color-img-headphone-' . time(),
            'category_id' => $this->category->id,
            'price' => 250,
            'stock_quantity' => 10,
            'description' => 'Supports color-specific imagery.',
        ]);

        $generalImg = $product->images()->create([
            'image_url' => 'https://example.com/general.jpg',
            'alt_text' => 'General View',
            'color_name' => null,
            'is_primary' => true,
            'display_order' => 0,
        ]);

        $midnightImg = $product->images()->create([
            'image_url' => 'https://example.com/midnight_blue.jpg',
            'alt_text' => 'Midnight Blue View',
            'color_name' => 'Midnight Blue',
            'is_primary' => false,
            'display_order' => 1,
        ]);

        $this->assertDatabaseHas('product_images', [
            'id' => $generalImg->id,
            'color_name' => null,
        ]);

        $this->assertDatabaseHas('product_images', [
            'id' => $midnightImg->id,
            'color_name' => 'Midnight Blue',
        ]);

        $showRes = $this->getJson("/api/admin/products/{$product->id}");
        $showRes->assertStatus(200);
        $images = $showRes->json('images');
        $this->assertCount(2, $images);
        $this->assertEquals('Midnight Blue', $images[1]['color_name']);
    }

    public function test_products_index_supports_media_filter_and_with_count(): void
    {
        $prodWithImg = Product::create([
            'name' => 'Prod With Image',
            'slug' => 'prod-with-img-' . time(),
            'category_id' => $this->category->id,
            'price' => 50,
            'stock_quantity' => 5,
            'description' => 'Has images.',
        ]);
        $prodWithImg->images()->create([
            'image_url' => 'https://example.com/prod.jpg',
            'alt_text' => 'Thumb',
            'is_primary' => true,
            'display_order' => 0,
        ]);

        $prodNoImg = Product::create([
            'name' => 'Prod Without Image',
            'slug' => 'prod-no-img-' . time(),
            'category_id' => $this->category->id,
            'price' => 40,
            'stock_quantity' => 2,
            'description' => 'No images.',
        ]);

        // Filter: has_images
        $resHas = $this->getJson('/api/admin/products?media_filter=has_images');
        $resHas->assertStatus(200);
        $idsHas = collect($resHas->json('data'))->pluck('id');
        $this->assertTrue($idsHas->contains($prodWithImg->id));
        $this->assertFalse($idsHas->contains($prodNoImg->id));

        // Filter: no_images
        $resNo = $this->getJson('/api/admin/products?media_filter=no_images');
        $resNo->assertStatus(200);
        $idsNo = collect($resNo->json('data'))->pluck('id');
        $this->assertTrue($idsNo->contains($prodNoImg->id));
        $this->assertFalse($idsNo->contains($prodWithImg->id));

        // images_count verification
        $firstItem = $resHas->json('data.0');
        $this->assertArrayHasKey('images_count', $firstItem);
    }
}
