<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

class UploadSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private string $pngContent;
    private string $jpgContent;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_test_upload@example.com'],
            [
                'name' => 'Admin Test',
                'password' => bcrypt('password123'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        Sanctum::actingAs($this->admin, ['admin:access']);

        // 1x1 valid PNG and JPEG byte streams
        $this->pngContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $this->jpgContent = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
    }

    public function test_product_image_upload_accepts_valid_formats_and_rejects_svg(): void
    {
        // 1. Valid PNG upload
        $pngFile = UploadedFile::fake()->createWithContent('product.png', $this->pngContent);
        $pngResponse = $this->postJson('/api/admin/products/upload-image', ['image' => $pngFile]);
        $pngResponse->assertStatus(201);
        $this->assertNotEmpty($pngResponse->json('image_url'));

        // 2. Valid JPG upload
        $jpgFile = UploadedFile::fake()->createWithContent('product.jpg', $this->jpgContent);
        $jpgResponse = $this->postJson('/api/admin/products/upload-image', ['image' => $jpgFile]);
        $jpgResponse->assertStatus(201);

        // 3. SVG upload rejected
        $svgFile = UploadedFile::fake()->createWithContent('product_xss.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert("xss")</script></svg>');
        $svgResponse = $this->postJson('/api/admin/products/upload-image', ['image' => $svgFile]);
        $svgResponse->assertStatus(422)->assertJsonValidationErrors(['image']);

        // 4. HTML/Script file rejected
        $htmlFile = UploadedFile::fake()->createWithContent('payload.html', '<html><script>alert(1)</script></html>');
        $htmlResponse = $this->postJson('/api/admin/products/upload-image', ['image' => $htmlFile]);
        $htmlResponse->assertStatus(422)->assertJsonValidationErrors(['image']);
    }

    public function test_category_image_upload_accepts_valid_formats_and_rejects_svg(): void
    {
        // 1. Valid PNG upload
        $pngFile = UploadedFile::fake()->createWithContent('category.png', $this->pngContent);
        $pngResponse = $this->postJson('/api/admin/categories/upload-image', ['image' => $pngFile]);
        $pngResponse->assertStatus(201);

        // 2. Valid JPG upload
        $jpgFile = UploadedFile::fake()->createWithContent('category.jpg', $this->jpgContent);
        $jpgResponse = $this->postJson('/api/admin/categories/upload-image', ['image' => $jpgFile]);
        $jpgResponse->assertStatus(201);

        // 3. SVG upload rejected
        $svgFile = UploadedFile::fake()->createWithContent('category_xss.svg', '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="40"/><script>alert("xss")</script></svg>');
        $svgResponse = $this->postJson('/api/admin/categories/upload-image', ['image' => $svgFile]);
        $svgResponse->assertStatus(422)->assertJsonValidationErrors(['image']);
    }

    public function test_brand_logo_upload_accepts_valid_formats_and_rejects_svg(): void
    {
        // 1. Valid PNG upload
        $pngFile = UploadedFile::fake()->createWithContent('brand_logo.png', $this->pngContent);
        $pngResponse = $this->postJson('/api/admin/branding/upload', ['image' => $pngFile]);
        $pngResponse->assertStatus(200);

        // 2. Valid JPG upload
        $jpgFile = UploadedFile::fake()->createWithContent('brand_logo.jpg', $this->jpgContent);
        $jpgResponse = $this->postJson('/api/admin/branding/upload', ['image' => $jpgFile]);
        $jpgResponse->assertStatus(200);

        // 3. SVG upload rejected
        $svgFile = UploadedFile::fake()->createWithContent('brand_xss.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert("brand_xss")</script></svg>');
        $svgResponse = $this->postJson('/api/admin/branding/upload', ['image' => $svgFile]);
        $svgResponse->assertStatus(422)->assertJsonValidationErrors(['image']);
    }

    public function test_banner_image_upload_accepts_valid_formats_and_rejects_svg(): void
    {
        // 1. Valid PNG upload
        $pngFile = UploadedFile::fake()->createWithContent('banner.png', $this->pngContent);
        $pngResponse = $this->postJson('/api/admin/banners/upload-image', ['image' => $pngFile]);
        $pngResponse->assertStatus(201);

        // 2. Valid JPG upload
        $jpgFile = UploadedFile::fake()->createWithContent('banner.jpg', $this->jpgContent);
        $jpgResponse = $this->postJson('/api/admin/banners/upload-image', ['image' => $jpgFile]);
        $jpgResponse->assertStatus(201);

        // 3. SVG upload rejected
        $svgFile = UploadedFile::fake()->createWithContent('banner_xss.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert("banner_xss")</script></svg>');
        $svgResponse = $this->postJson('/api/admin/banners/upload-image', ['image' => $svgFile]);
        $svgResponse->assertStatus(422)->assertJsonValidationErrors(['image']);
    }
}
