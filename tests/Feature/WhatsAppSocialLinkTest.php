<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\SocialLink;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;

class WhatsAppSocialLinkTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_whatsapp_test@example.com'],
            [
                'name' => 'Admin WhatsApp Tester',
                'password' => bcrypt('password123'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        Sanctum::actingAs($this->admin, ['*']);
    }

    public function test_can_create_whatsapp_social_link_with_normalization(): void
    {
        // Delete any existing whatsapp link
        SocialLink::whereRaw('LOWER(platform) = ?', ['whatsapp'])->delete();

        $response = $this->postJson('/api/admin/online-store/social-links', [
            'platform' => 'WhatsApp',
            'whatsapp_number' => '+880 1711-223344',
            'whatsapp_message' => 'Hello! I need assistance with my order.',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $data = $response->json('social_link');

        $this->assertEquals('WhatsApp', $data['platform']);
        $this->assertEquals('whatsapp', $data['icon']);
        $this->assertStringStartsWith('https://wa.me/8801711223344', $data['url']);
        $this->assertStringContainsString('text=Hello%21%20I%20need%20assistance%20with%20my%20order.', $data['url']);
    }

    public function test_rejects_invalid_whatsapp_number(): void
    {
        SocialLink::whereRaw('LOWER(platform) = ?', ['whatsapp'])->delete();

        // Too short (3 digits)
        $response = $this->postJson('/api/admin/online-store/social-links', [
            'platform' => 'WhatsApp',
            'whatsapp_number' => '123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['whatsapp_number']);
    }

    public function test_prevents_duplicate_whatsapp_links(): void
    {
        SocialLink::whereRaw('LOWER(platform) = ?', ['whatsapp'])->delete();

        // First creation succeeds
        $first = $this->postJson('/api/admin/online-store/social-links', [
            'platform' => 'WhatsApp',
            'whatsapp_number' => '8801711111111',
            'is_active' => true,
        ]);
        $first->assertStatus(201);

        // Second creation should fail with 422
        $second = $this->postJson('/api/admin/online-store/social-links', [
            'platform' => 'WhatsApp',
            'whatsapp_number' => '8801999999999',
            'is_active' => true,
        ]);
        $second->assertStatus(422)
            ->assertJsonValidationErrors(['platform']);
    }

    public function test_can_update_existing_whatsapp_link(): void
    {
        SocialLink::whereRaw('LOWER(platform) = ?', ['whatsapp'])->delete();

        $link = SocialLink::create([
            'platform' => 'WhatsApp',
            'url' => 'https://wa.me/8801711111111',
            'icon' => 'whatsapp',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $response = $this->putJson("/api/admin/online-store/social-links/{$link->id}", [
            'platform' => 'WhatsApp',
            'whatsapp_number' => '8801822222222',
            'whatsapp_message' => 'Updated message',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $response->assertStatus(200);
        $updated = $response->json('social_link');
        $this->assertEquals('https://wa.me/8801822222222?text=Updated%20message', $updated['url']);
    }

    public function test_public_store_navigation_includes_whatsapp_link(): void
    {
        SocialLink::whereRaw('LOWER(platform) = ?', ['whatsapp'])->delete();

        SocialLink::create([
            'platform' => 'WhatsApp',
            'url' => 'https://wa.me/8801711111111?text=Inquiry',
            'icon' => 'whatsapp',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/store-navigation');
        $response->assertStatus(200);

        $socials = $response->json('social_links');
        $wa = collect($socials)->firstWhere('platform', 'WhatsApp');

        $this->assertNotNull($wa);
        $this->assertEquals('https://wa.me/8801711111111?text=Inquiry', $wa['url']);
        $this->assertEquals('whatsapp', $wa['icon']);
    }

    public function test_standard_social_links_still_work(): void
    {
        $response = $this->postJson('/api/admin/online-store/social-links', [
            'platform' => 'Instagram',
            'url' => 'https://instagram.com/myvapestore',
            'icon' => 'instagram',
            'sort_order' => 5,
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $data = $response->json('social_link');
        $this->assertEquals('https://instagram.com/myvapestore', $data['url']);
        $this->assertEquals('instagram', $data['icon']);
    }
}
