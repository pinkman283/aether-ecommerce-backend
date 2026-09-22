<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOrderDeleteTest extends TestCase
{
    private function createAdmin(): User
    {
        return User::firstOrCreate(
            ['email' => 'admin_test_delete@example.com'],
            [
                'name' => 'Admin Delete Tester',
                'password' => bcrypt('password123'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );
    }

    private function getOrCreateProduct(): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'test-cat-del'],
            ['name' => 'Test Cat Del']
        );

        return Product::firstOrCreate(
            ['slug' => 'test-product-del'],
            [
                'category_id' => $category->id,
                'name' => 'Test Product Del',
                'price' => 100,
                'stock_quantity' => 10,
                'is_active' => true,
            ]
        );
    }

    public function test_admin_can_delete_single_order(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin, ['admin:access']);

        $product = $this->getOrCreateProduct();
        $order = Order::create([
            'order_number' => 'ORD-DEL-' . uniqid(),
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'shipping_address' => '123 Test St',
            'subtotal' => 200,
            'total_amount' => 200,
            'payment_status' => 'pending',
            'payment_method' => 'cash',
            'order_status' => 'pending',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'unit_price' => 100,
            'total_price' => 200,
        ]);

        $response = $this->deleteJson("/api/admin/orders/{$order->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_admin_can_bulk_delete_orders(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin, ['admin:access']);

        $product = $this->getOrCreateProduct();
        $order1 = Order::create([
            'order_number' => 'ORD-BULK1-' . uniqid(),
            'customer_name' => 'John Doe 1',
            'customer_email' => 'john1@example.com',
            'shipping_address' => '123 Test St',
            'subtotal' => 50,
            'total_amount' => 50,
            'payment_status' => 'pending',
            'payment_method' => 'cash',
            'order_status' => 'pending',
        ]);

        OrderItem::create([
            'order_id' => $order1->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 50,
            'total_price' => 50,
        ]);

        $order2 = Order::create([
            'order_number' => 'ORD-BULK2-' . uniqid(),
            'customer_name' => 'John Doe 2',
            'customer_email' => 'john2@example.com',
            'shipping_address' => '123 Test St',
            'subtotal' => 50,
            'total_amount' => 50,
            'payment_status' => 'pending',
            'payment_method' => 'cash',
            'order_status' => 'pending',
        ]);

        OrderItem::create([
            'order_id' => $order2->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 50,
            'total_price' => 50,
        ]);

        $response = $this->postJson("/api/admin/orders/bulk-delete", [
            'ids' => [$order1->id, $order2->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['deleted_count' => 2]);
        $this->assertSoftDeleted('orders', ['id' => $order1->id]);
        $this->assertSoftDeleted('orders', ['id' => $order2->id]);
    }
}
