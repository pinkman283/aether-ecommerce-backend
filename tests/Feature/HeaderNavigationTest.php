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

    public function test_arbitrary_depth_nesting_and_public_filtering(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin, ['admin:access']);

        // Clear cache
        Cache::forget('storefront_header_navigation');

        // Create a 4-level deep hierarchy: Level 1 -> Level 2 -> Level 3 -> Level 4
        $l1 = NavbarItem::create([
            'title' => 'L1 Root',
            'type' => 'custom',
            'url' => '/l1',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $l2 = NavbarItem::create([
            'parent_id' => $l1->id,
            'title' => 'L2 Branch',
            'type' => 'custom',
            'url' => '/l2',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $l3 = NavbarItem::create([
            'parent_id' => $l2->id,
            'title' => 'L3 Sub-branch',
            'type' => 'custom',
            'url' => '/l3',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $l4 = NavbarItem::create([
            'parent_id' => $l3->id,
            'title' => 'L4 Leaf',
            'type' => 'custom',
            'url' => '/l4',
            'display_order' => 1,
            'is_active' => true,
        ]);

        // Verify public header renders all 4 levels recursively
        $response = $this->getJson('/api/navigation/header');
        $response->assertStatus(200);

        $rootItem = collect($response->json())->firstWhere('id', $l1->id);
        $this->assertNotNull($rootItem);
        $this->assertCount(1, $rootItem['children']);
        $this->assertEquals($l2->id, $rootItem['children'][0]['id']);

        $tier2 = $rootItem['children'][0];
        $this->assertCount(1, $tier2['children']);
        $this->assertEquals($l3->id, $tier2['children'][0]['id']);

        $tier3 = $tier2['children'][0];
        $this->assertCount(1, $tier3['children']);
        $this->assertEquals($l4->id, $tier3['children'][0]['id']);

        // Clean up
        $l1->delete();
    }

    public function test_invalid_and_deleted_target_handling(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin, ['admin:access']);

        // Create a category and brand
        $category = Category::create([
            'name' => 'Disposable Vape',
            'slug' => 'disposable-vape-' . uniqid(),
        ]);

        $brand = Brand::create([
            'name' => 'Voltbar Brand',
            'slug' => 'voltbar-brand-' . uniqid(),
        ]);

        // Item 1: Valid category item
        $validItem = NavbarItem::create([
            'title' => 'Disposables',
            'type' => 'category',
            'category_id' => $category->id,
            'is_active' => true,
            'display_order' => 1,
        ]);

        // Item 2: Brand item with existing brand
        $brandItem = NavbarItem::create([
            'parent_id' => $validItem->id,
            'title' => 'Voltbar',
            'type' => 'brand',
            'brand_id' => $brand->id,
            'is_active' => true,
            'display_order' => 1,
        ]);

        // Item 3: Broken brand item pointing to a deleted/null brand
        $brokenItem = NavbarItem::create([
            'parent_id' => $validItem->id,
            'title' => 'Deleted Brand Item',
            'type' => 'brand',
            'brand_id' => null, // target missing/deleted
            'is_active' => true,
            'display_order' => 2,
        ]);

        $this->assertFalse($brokenItem->is_valid);
        $this->assertNotNull($brokenItem->invalid_reason);
        $this->assertNull($brokenItem->computed_url); // Must NEVER be '#'

        // Fetch admin tree: broken item must be returned with actionable warning
        $adminTree = $this->getJson('/api/admin/navigation/tree');
        $adminTree->assertStatus(200);
        $adminRoot = collect($adminTree->json())->firstWhere('id', $validItem->id);
        $this->assertNotNull($adminRoot);

        $brokenInAdmin = collect($adminRoot['children'])->firstWhere('id', $brokenItem->id);
        $this->assertNotNull($brokenInAdmin);
        $this->assertFalse($brokenInAdmin['is_valid']);
        $this->assertEquals('Referenced brand was deleted or not selected.', $brokenInAdmin['invalid_reason']);

        // Fetch public header: broken item must be completely omitted from public storefront
        Cache::forget('storefront_header_navigation');
        $publicRes = $this->getJson('/api/navigation/header');
        $publicRes->assertStatus(200);
        $publicRoot = collect($publicRes->json())->firstWhere('id', $validItem->id);
        $this->assertNotNull($publicRoot);

        $publicChildIds = collect($publicRoot['children'])->pluck('id')->all();
        $this->assertContains($brandItem->id, $publicChildIds);
        $this->assertNotContains($brokenItem->id, $publicChildIds);

        // Clean up
        $validItem->delete();
        $category->delete();
        $brand->delete();
    }

    public function test_cycle_detection_prevents_circular_hierarchy(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin, ['admin:access']);

        $itemA = NavbarItem::create([
            'title' => 'Node A',
            'type' => 'custom',
            'url' => '/a',
            'is_active' => true,
        ]);

        $itemB = NavbarItem::create([
            'parent_id' => $itemA->id,
            'title' => 'Node B',
            'type' => 'custom',
            'url' => '/b',
            'is_active' => true,
        ]);

        // 1. Attempt to set itemA's parent to itself
        $resSelf = $this->putJson("/api/admin/navigation/{$itemA->id}", [
            'parent_id' => $itemA->id,
        ]);
        $resSelf->assertStatus(422);

        // 2. Attempt to set itemA's parent to its child itemB (circular reference)
        $resCircle = $this->putJson("/api/admin/navigation/{$itemA->id}", [
            'parent_id' => $itemB->id,
        ]);
        $resCircle->assertStatus(422);

        // Clean up
        $itemA->delete();
    }

    public function test_duplicate_endpoint_clones_entire_subtree(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin, ['admin:access']);

        $root = NavbarItem::create([
            'title' => 'Menu To Clone',
            'type' => 'custom',
            'url' => '/clone-me',
            'is_active' => true,
        ]);

        $child = NavbarItem::create([
            'parent_id' => $root->id,
            'title' => 'Child Item',
            'type' => 'custom',
            'url' => '/child',
            'is_active' => true,
        ]);

        $duplicateRes = $this->postJson("/api/admin/navigation/{$root->id}/duplicate");
        $duplicateRes->assertStatus(201);

        $clonedId = $duplicateRes->json('item.id');
        $this->assertNotEquals($root->id, $clonedId);

        $clonedItem = NavbarItem::with('children')->find($clonedId);
        $this->assertNotNull($clonedItem);
        $this->assertEquals('Menu To Clone (Copy)', $clonedItem->title);
        $this->assertCount(1, $clonedItem->children);
        $this->assertEquals('Child Item', $clonedItem->children[0]->title);
        $this->assertNotEquals($child->id, $clonedItem->children[0]->id);

        // Clean up
        $root->delete();
        $clonedItem->delete();
    }
}
