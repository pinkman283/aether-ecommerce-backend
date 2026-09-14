<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\BrandLogo;
use App\Models\BrandLogoPlacement;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;

class BrandingLogoManagementTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        BrandLogoPlacement::query()->delete();
        BrandLogo::query()->delete();

        $this->admin = User::firstOrCreate(
            ['email' => 'ss@gmail.com'],
            [
                'name' => 'Super Admin',
                'password' => bcrypt('ss112233'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        Sanctum::actingAs($this->admin, ['admin:access']);
    }

    public function test_can_list_brand_logos_and_favicon(): void
    {
        $response = $this->getJson('/api/admin/branding/logos');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'logos',
                'favicon',
                'available_placements',
            ]);
    }

    public function test_can_create_brand_logo_with_placements(): void
    {
        $response = $this->postJson('/api/admin/branding/logos', [
            'name' => 'Modern Dark Mark',
            'image_url' => 'https://example.com/logo-dark.png',
            'placements' => ['navbar', 'footer'],
        ]);

        $response->assertStatus(201);
        $logoId = $response->json('logo.id');

        $this->assertDatabaseHas('brand_logos', [
            'id' => $logoId,
            'name' => 'Modern Dark Mark',
            'image_url' => 'https://example.com/logo-dark.png',
        ]);

        $this->assertDatabaseHas('brand_logo_placements', [
            'logo_id' => $logoId,
            'placement' => 'navbar',
        ]);
        $this->assertDatabaseHas('brand_logo_placements', [
            'logo_id' => $logoId,
            'placement' => 'footer',
        ]);
    }

    public function test_conflict_resolution_reassigns_placement_atomically(): void
    {
        // 1. Create Logo 1 assigned to navbar and footer
        $logo1 = BrandLogo::create([
            'name' => 'Logo 1',
            'image_url' => 'https://example.com/logo1.png',
        ]);
        BrandLogoPlacement::create(['logo_id' => $logo1->id, 'placement' => 'navbar']);
        BrandLogoPlacement::create(['logo_id' => $logo1->id, 'placement' => 'footer']);

        // 2. Create Logo 2 and assign it to navbar
        $res = $this->postJson('/api/admin/branding/logos', [
            'name' => 'Logo 2',
            'image_url' => 'https://example.com/logo2.png',
            'placements' => ['navbar'],
        ]);

        $res->assertStatus(201);
        $logo2Id = $res->json('logo.id');

        // Verify: navbar placement now belongs to Logo 2
        $this->assertDatabaseHas('brand_logo_placements', [
            'logo_id' => $logo2Id,
            'placement' => 'navbar',
        ]);

        // Verify: Logo 1 lost navbar, but kept footer
        $this->assertDatabaseMissing('brand_logo_placements', [
            'logo_id' => $logo1->id,
            'placement' => 'navbar',
        ]);
        $this->assertDatabaseHas('brand_logo_placements', [
            'logo_id' => $logo1->id,
            'placement' => 'footer',
        ]);

        // Exactly one record for navbar in entire table
        $this->assertEquals(1, BrandLogoPlacement::where('placement', 'navbar')->count());
    }

    public function test_favicon_can_be_set_and_removed_independently(): void
    {
        // Update favicon
        $updateRes = $this->putJson('/api/admin/branding/favicon', [
            'favicon_url' => 'https://example.com/favicon-custom.ico',
        ]);

        $updateRes->assertStatus(200)
            ->assertJson(['favicon' => 'https://example.com/favicon-custom.ico']);

        $this->assertEquals('https://example.com/favicon-custom.ico', Setting::get('store_favicon'));

        // Public settings reflects separate favicon
        $publicRes = $this->getJson('/api/theme-settings');
        $publicRes->assertStatus(200)
            ->assertJsonPath('store_favicon', 'https://example.com/favicon-custom.ico');

        // Remove favicon
        $deleteRes = $this->deleteJson('/api/admin/branding/favicon');

        $deleteRes->assertStatus(200);
        $this->assertEquals('', Setting::get('store_favicon'));
    }

    public function test_public_theme_settings_resolves_logo_placements(): void
    {
        // Setup specific placements
        $logo = BrandLogo::create([
            'name' => 'Showcase Header Logo',
            'image_url' => 'https://example.com/showcase.png',
        ]);
        BrandLogoPlacement::where('placement', 'navbar')->delete();
        BrandLogoPlacement::create(['logo_id' => $logo->id, 'placement' => 'navbar']);

        Cache::flush();

        $res = $this->getJson('/api/theme-settings');
        $res->assertStatus(200);

        $this->assertEquals('https://example.com/showcase.png', $res->json('logo_placements.navbar'));
        $this->assertEquals('https://example.com/showcase.png', $res->json('store_brand_logo'));
    }
}

