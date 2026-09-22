<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\NavbarItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HeaderNavigationTest extends TestCase
{
    private function createAdmin(): User
    {
        return User::firstOrCreate(
            ['email' => 'admin_nav_tester@example.com'],
            [
                'name' => 'Admin Nav Tester',
                'password' => bcrypt('secret123'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );
    }

    public function test_public_header_navigation_returns_active_tree(): void
    {
        Cache::forget('storefront_header_navigation');

        $response = $this->getJson('/api/navigation/header');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            '*' => [
                'id',
                'title',
                'type',
                'url',
                'display_order',
                'mega_menu_type',
                'children',
            ]
        ]);
    }

    public function test_admin_can_create_update_reorder_and_delete_navbar_item(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin, ['admin:access']);

        // 1. Create top-level item
        $createResponse = $this->postJson('/api/admin/navigation', [
            'title' => 'Gaming Gear',
            'type' => 'custom',
            'url' => '/gaming-gear',
            'display_order' => 99,
            'is_active' => true,
            'mega_menu_type' => 'none',
            'badge' => 'HOT',
            'badge_color' => '#ef4444',
        ]);

        $createResponse->assertStatus(201);
        $itemId = $createResponse->json('item.id');
        $this->assertNotNull($itemId);

        // 2. Fetch admin navigation list
        $listResponse = $this->getJson('/api/admin/navigation');
        $listResponse->assertStatus(200);
        $this->assertTrue(collect($listResponse->json())->contains('id', $itemId));

        // 3. Update item
        $updateResponse = $this->putJson("/api/admin/navigation/{$itemId}", [
            'title' => 'Pro Gaming Gear',
            'type' => 'custom',
            'url' => '/pro-gaming',
            'display_order' => 10,
            'is_active' => true,
            'mega_menu_type' => 'columns',
            'badge' => 'PRO',
            'badge_color' => '#8b5cf6',
        ]);

        $updateResponse->assertStatus(200);
        $this->assertEquals('Pro Gaming Gear', $updateResponse->json('item.title'));
        $this->assertEquals('/pro-gaming', $updateResponse->json('item.url'));
        $this->assertEquals('columns', $updateResponse->json('item.mega_menu_type'));

        // 4. Reorder items
        $reorderResponse = $this->postJson('/api/admin/navigation/reorder', [
            'items' => [
                ['id' => $itemId, 'display_order' => 1, 'parent_id' => null],
            ],
        ]);
        $reorderResponse->assertStatus(200);
        $this->assertEquals(1, NavbarItem::find($itemId)->display_order);

        // 5. Delete item
        $deleteResponse = $this->deleteJson("/api/admin/navigation/{$itemId}");
        $deleteResponse->assertStatus(200);
        $this->assertNull(NavbarItem::find($itemId));
    }

    public function test_category_brand_pivot_syncing_and_product_dual_sync(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin, ['admin:access']);

        $category = Category::firstOrCreate(
            ['slug' => 'test-nav-category'],
            ['name' => 'Test Nav Category', 'description' => 'Test']
        );

        $brand = Brand::firstOrCreate(
            ['slug' => 'test-nav-brand'],
            ['name' => 'Test Nav Brand', 'is_active' => true]
        );

        // 1. Sync category brands via admin endpoint
        $syncResponse = $this->putJson("/api/admin/navigation/category-brands/{$category->id}", [
            'brands' => [
                [
                    'brand_id' => $brand->id,
                    'is_in_navbar' => true,
                    'is_featured' => true,
                    'display_order' => 1,
                ]
            ]
        ]);

        $syncResponse->assertStatus(200);
        $this->assertTrue($category->fresh()->brands->contains('id', $brand->id));

        // 2. Test Product creation dual-sync: saving brand_id populates products.brand and category_brand pivot
        $product = Product::create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'name' => 'Test Dual Sync Product',
            'slug' => 'test-dual-sync-' . uniqid(),
            'sku' => 'DUAL-' . strtoupper(uniqid()),
            'price' => 299.99,
            'stock_quantity' => 5,
            'description' => 'Dual sync test',
        ]);

        $product->refresh();
        $this->assertEquals($brand->name, $product->brand);
        $this->assertEquals($brand->id, $product->brand_id);
    }
}
