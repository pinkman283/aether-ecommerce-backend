<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\HomepageSection;
use App\Models\NavbarItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminBrandTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_brand_tester@example.com'],
            [
                'name' => 'Admin Brand Tester',
                'password' => bcrypt('secret123'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);
    }

    public function test_admin_can_list_brands(): void
    {
        $this->actAsAdmin();

        $brand = Brand::create([
            'name' => 'List Test Brand ' . Str::random(5),
            'slug' => 'list-brand-' . Str::random(8),
            'is_featured' => true,
            'display_order' => 1,
        ]);

        $response = $this->getJson('/api/admin/brands');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            '*' => [
                'id',
                'name',
                'slug',
                'is_featured',
                'display_order',
                'products_count',
                'categories_count',
            ],
        ]);
    }

    public function test_admin_can_create_brand(): void
    {
        $this->actAsAdmin();

        $payload = [
            'name' => 'Brand Creation Test ' . Str::random(5),
            'description' => 'Top tier tech manufacturer',
            'website' => 'https://example.com/brand',
            'logo' => 'https://example.com/logo.png',
            'is_featured' => true,
            'display_order' => 5,
        ];

        $response = $this->postJson('/api/admin/brands', $payload);

        $response->assertStatus(201);
        $response->assertJsonFragment([
            'name' => $payload['name'],
            'website' => $payload['website'],
            'is_featured' => true,
            'display_order' => 5,
        ]);
        $this->assertDatabaseHas('brands', ['name' => $payload['name']]);
    }

    public function test_admin_can_update_brand(): void
    {
        $this->actAsAdmin();

        $brand = Brand::create([
            'name' => 'Brand Before Update ' . Str::random(5),
            'slug' => 'brand-before-' . Str::random(8),
            'website' => 'https://old.com',
            'is_featured' => false,
        ]);

        $response = $this->putJson("/api/admin/brands/{$brand->id}", [
            'name' => 'Brand After Update ' . Str::random(5),
            'website' => 'https://new.com',
            'is_featured' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'website' => 'https://new.com',
            'is_featured' => true,
        ]);
        $this->assertDatabaseHas('brands', [
            'id' => $brand->id,
            'website' => 'https://new.com',
        ]);
    }

    public function test_admin_can_show_brand(): void
    {
        $this->actAsAdmin();

        $brand = Brand::create([
            'name' => 'Show Brand Test ' . Str::random(5),
            'slug' => 'show-brand-' . Str::random(8),
        ]);

        $response = $this->getJson("/api/admin/brands/{$brand->id}");

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'id' => $brand->id,
            'name' => $brand->name,
        ]);
        $this->assertArrayHasKey('products_count', $response->json());
        $this->assertArrayHasKey('categories_count', $response->json());
        $this->assertArrayHasKey('products', $response->json());
    }

    public function test_safe_brand_can_be_deleted(): void
    {
        $this->actAsAdmin();

        $brand = Brand::create([
            'name' => 'Safe Brand To Delete ' . Str::random(5),
            'slug' => 'safe-brand-' . Str::random(8),
        ]);

        $response = $this->deleteJson("/api/admin/brands/{$brand->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
    }

    public function test_brand_deletion_blocked_when_products_reference_it(): void
    {
        $this->actAsAdmin();

        $brand = Brand::create([
            'name' => 'Brand With Product ' . Str::random(5),
            'slug' => 'brand-prod-' . Str::random(8),
        ]);

        $product = Product::create([
            'name' => 'Hardware Product ' . Str::random(5),
            'slug' => 'hard-prod-' . Str::random(8),
            'brand_id' => $brand->id,
            'price' => 299.99,
            'stock_quantity' => 15,
            'description' => 'Test product description',
        ]);

        $response = $this->deleteJson("/api/admin/brands/{$brand->id}");

        $response->assertStatus(422);
        $response->assertJsonPath('conflicts.products', 1);
        $this->assertDatabaseHas('brands', ['id' => $brand->id]);
    }

    public function test_brand_deletion_blocked_when_category_brand_references_it(): void
    {
        $this->actAsAdmin();

        $brand = Brand::create([
            'name' => 'Brand With Category ' . Str::random(5),
            'slug' => 'brand-cat-' . Str::random(8),
        ]);

        $category = Category::create([
            'name' => 'Category For Brand ' . Str::random(5),
            'slug' => 'cat-brand-' . Str::random(8),
        ]);

        DB::table('category_brand')->insert([
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'display_order' => 0,
            'is_in_navbar' => true,
        ]);

        $response = $this->deleteJson("/api/admin/brands/{$brand->id}");

        $response->assertStatus(422);
        $response->assertJsonPath('conflicts.categories', 1);
        $this->assertDatabaseHas('brands', ['id' => $brand->id]);
    }

    public function test_brand_deletion_blocked_when_navbar_items_reference_it(): void
    {
        $this->actAsAdmin();

        $brand = Brand::create([
            'name' => 'Brand In Nav ' . Str::random(5),
            'slug' => 'brand-nav-' . Str::random(8),
        ]);

        NavbarItem::create([
            'title' => 'Nav Brand Link',
            'type' => 'brand',
            'brand_id' => $brand->id,
            'display_order' => 1,
            'is_active' => true,
        ]);

        $response = $this->deleteJson("/api/admin/brands/{$brand->id}");

        $response->assertStatus(422);
        $response->assertJsonPath('conflicts.navigation', 1);
        $this->assertDatabaseHas('brands', ['id' => $brand->id]);
    }

    public function test_brand_deletion_blocked_when_homepage_sections_brand_id_references_it(): void
    {
        $this->actAsAdmin();

        $brand = Brand::create([
            'name' => 'Brand In Homepage ' . Str::random(5),
            'slug' => 'brand-home-' . Str::random(8),
        ]);

        HomepageSection::create([
            'title' => 'Brand Showcase Section',
            'slug' => 'showcase-' . Str::random(8),
            'brand_id' => $brand->id,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $response = $this->deleteJson("/api/admin/brands/{$brand->id}");

        $response->assertStatus(422);
        $response->assertJsonPath('conflicts.homepage_sections', 1);
        $this->assertDatabaseHas('brands', ['id' => $brand->id]);
    }

    public function test_brand_deletion_blocked_when_homepage_sections_view_all_brand_id_references_it(): void
    {
        $this->actAsAdmin();

        $brand = Brand::create([
            'name' => 'Brand In View All ' . Str::random(5),
            'slug' => 'brand-va-' . Str::random(8),
        ]);

        HomepageSection::create([
            'title' => 'View All Brand Section',
            'slug' => 'view-all-' . Str::random(8),
            'view_all_brand_id' => $brand->id,
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $response = $this->deleteJson("/api/admin/brands/{$brand->id}");

        $response->assertStatus(422);
        $response->assertJsonPath('conflicts.homepage_sections', 1);
        $this->assertDatabaseHas('brands', ['id' => $brand->id]);
    }

    public function test_bulk_delete_deletes_safe_brands(): void
    {
        $this->actAsAdmin();

        $brand1 = Brand::create([
            'name' => 'Safe Bulk 1 ' . Str::random(5),
            'slug' => 'safe-bulk-1-' . Str::random(8),
        ]);

        $brand2 = Brand::create([
            'name' => 'Safe Bulk 2 ' . Str::random(5),
            'slug' => 'safe-bulk-2-' . Str::random(8),
        ]);

        $response = $this->postJson('/api/admin/brands/bulk-delete', [
            'ids' => [$brand1->id, $brand2->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'deleted_count' => 2,
            'skipped_count' => 0,
        ]);

        $this->assertDatabaseMissing('brands', ['id' => $brand1->id]);
        $this->assertDatabaseMissing('brands', ['id' => $brand2->id]);
    }

    public function test_bulk_delete_skips_blocked_brands_and_deletes_safe_brands(): void
    {
        $this->actAsAdmin();

        $safeBrand = Brand::create([
            'name' => 'Safe Brand Bulk ' . Str::random(5),
            'slug' => 'safe-brand-bulk-' . Str::random(8),
        ]);

        $blockedBrand = Brand::create([
            'name' => 'Blocked Brand Bulk ' . Str::random(5),
            'slug' => 'blocked-brand-bulk-' . Str::random(8),
        ]);

        Product::create([
            'name' => 'Product For Bulk Test ' . Str::random(5),
            'slug' => 'prod-bulk-' . Str::random(8),
            'brand_id' => $blockedBrand->id,
            'price' => 149.99,
            'stock_quantity' => 10,
            'description' => 'Test product description',
        ]);

        $response = $this->postJson('/api/admin/brands/bulk-delete', [
            'ids' => [$safeBrand->id, $blockedBrand->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'deleted_count' => 1,
            'skipped_count' => 1,
        ]);

        $this->assertDatabaseMissing('brands', ['id' => $safeBrand->id]);
        $this->assertDatabaseHas('brands', ['id' => $blockedBrand->id]);
    }

    public function test_logo_upload_is_authorized_and_validates_file_type_and_size(): void
    {
        Storage::fake('public');

        // 1. Unauthorized request
        $pngContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $validFile = UploadedFile::fake()->createWithContent('brand_logo.png', $pngContent);

        $unauthResponse = $this->postJson('/api/admin/brands/upload-logo', ['logo' => $validFile]);
        $this->assertTrue(in_array($unauthResponse->status(), [401, 403]));

        // 2. Authorized valid upload
        $this->actAsAdmin();
        $authResponse = $this->postJson('/api/admin/brands/upload-logo', ['logo' => $validFile]);
        $authResponse->assertStatus(200);
        $authResponse->assertJsonStructure(['logo_url', 'image_url', 'path', 'filename']);

        // 3. SVG file is rejected (not in mimes:jpeg,png,jpg,gif,webp,avif)
        $svgFile = UploadedFile::fake()->createWithContent('malicious.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $svgResponse = $this->postJson('/api/admin/brands/upload-logo', ['logo' => $svgFile]);
        $svgResponse->assertStatus(422)->assertJsonValidationErrors(['logo']);

        // 4. Oversized file (>20480 KB) is rejected
        $largeFile = UploadedFile::fake()->create('large_logo.png', 25000, 'image/png');
        $largeResponse = $this->postJson('/api/admin/brands/upload-logo', ['logo' => $largeFile]);
        $largeResponse->assertStatus(422)->assertJsonValidationErrors(['logo']);
    }
}
