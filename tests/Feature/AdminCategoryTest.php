<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCategoryTest extends TestCase
{
    private function createAdmin(): User
    {
        return User::firstOrCreate(
            ['email' => 'admin_cat_tester@example.com'],
            [
                'name' => 'Admin Cat Tester',
                'password' => bcrypt('secret123'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );
    }

    public function test_category_deletion_succeeds_when_safe(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin, ['admin:access']);

        $category = Category::create([
            'name' => 'Safe Category To Delete ' . Str::random(5),
            'slug' => 'safe-cat-' . Str::random(8),
        ]);

        $response = $this->deleteJson("/api/admin/categories/{$category->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_category_deletion_blocked_when_products_category_id_references_it(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin, ['admin:access']);

        $category = Category::create([
            'name' => 'Direct Parent Category ' . Str::random(5),
            'slug' => 'direct-parent-' . Str::random(8),
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Direct Product ' . Str::random(5),
            'slug' => 'direct-prod-' . Str::random(8),
            'price' => 199.99,
            'stock_quantity' => 10,
            'description' => 'Test product description',
        ]);

        $response = $this->deleteJson("/api/admin/categories/{$category->id}");

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => "Cannot delete category '{$category->name}' because there are 1 product assigned directly. Reassign or remove these relationships first.",
        ]);
        $this->assertDatabaseHas('categories', ['id' => $category->id]);

        // Clean up product
        $product->delete();
        $category->delete();
    }

    public function test_category_deletion_blocked_when_products_subcategory_id_references_it(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin, ['admin:access']);

        $rootCategory = Category::create([
            'name' => 'Root Cat ' . Str::random(5),
            'slug' => 'root-cat-' . Str::random(8),
        ]);

        $subCategory = Category::create([
            'name' => 'Subcategory Under Test ' . Str::random(5),
            'slug' => 'sub-cat-' . Str::random(8),
            'parent_id' => $rootCategory->id,
        ]);

        $product = Product::create([
            'category_id' => $rootCategory->id,
            'subcategory_id' => $subCategory->id,
            'name' => 'Subcategory Linked Product ' . Str::random(5),
            'slug' => 'sub-prod-' . Str::random(8),
            'price' => 89.99,
            'stock_quantity' => 5,
            'description' => 'Test subcategory product description',
        ]);

        $response = $this->deleteJson("/api/admin/categories/{$subCategory->id}");

        $response->assertStatus(422);
        $this->assertStringContainsString('using it as a subcategory', $response->json('message'));
        $this->assertDatabaseHas('categories', ['id' => $subCategory->id]);

        // Clean up product and categories
        $product->delete();
        $subCategory->delete();
        $rootCategory->delete();
    }

    public function test_category_deletion_blocked_when_child_categories_exist(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin, ['admin:access']);

        $parent = Category::create([
            'name' => 'Parent With Child ' . Str::random(5),
            'slug' => 'parent-with-child-' . Str::random(8),
        ]);

        $child = Category::create([
            'name' => 'Child Category ' . Str::random(5),
            'slug' => 'child-cat-' . Str::random(8),
            'parent_id' => $parent->id,
        ]);

        $response = $this->deleteJson("/api/admin/categories/{$parent->id}");

        $response->assertStatus(422);
        $this->assertStringContainsString('child subcategory under it', $response->json('message'));
        $this->assertDatabaseHas('categories', ['id' => $parent->id]);

        // Clean up
        $child->delete();
        $parent->delete();
    }

    public function test_bulk_deletion_skips_unsafe_categories(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin, ['admin:access']);

        $safeCat = Category::create([
            'name' => 'Safe Bulk ' . Str::random(5),
            'slug' => 'safe-bulk-' . Str::random(8),
        ]);

        $blockedCat = Category::create([
            'name' => 'Blocked Bulk ' . Str::random(5),
            'slug' => 'blocked-bulk-' . Str::random(8),
        ]);

        $product = Product::create([
            'category_id' => $blockedCat->id,
            'name' => 'Bulk Block Product ' . Str::random(5),
            'slug' => 'bulk-prod-' . Str::random(8),
            'price' => 49.99,
            'stock_quantity' => 20,
            'description' => 'Test bulk product',
        ]);

        $response = $this->postJson('/api/admin/categories/bulk-delete', [
            'ids' => [$safeCat->id, $blockedCat->id],
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'deleted_count' => 1,
            'skipped_count' => 1,
        ]);

        $this->assertDatabaseMissing('categories', ['id' => $safeCat->id]);
        $this->assertDatabaseHas('categories', ['id' => $blockedCat->id]);

        // Clean up
        $product->delete();
        $blockedCat->delete();
    }

    public function test_circular_parent_assignment_is_rejected(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin, ['admin:access']);

        $root = Category::create([
            'name' => 'Root Ancestor ' . Str::random(5),
            'slug' => 'root-anc-' . Str::random(8),
        ]);

        $child = Category::create([
            'name' => 'Child Descendant ' . Str::random(5),
            'slug' => 'child-desc-' . Str::random(8),
            'parent_id' => $root->id,
        ]);

        // Attempt to set root's parent_id to its own child
        $response = $this->putJson("/api/admin/categories/{$root->id}", [
            'parent_id' => $child->id,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Cannot set a descendant category as parent.',
        ]);

        // Clean up
        $child->delete();
        $root->delete();
    }

    public function test_category_cannot_be_assigned_itself_as_parent(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin, ['admin:access']);

        $category = Category::create([
            'name' => 'Self Parent Target ' . Str::random(5),
            'slug' => 'self-parent-' . Str::random(8),
        ]);

        $response = $this->putJson("/api/admin/categories/{$category->id}", [
            'parent_id' => $category->id,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'A category cannot be its own parent.',
        ]);

        // Clean up
        $category->delete();
    }
}
