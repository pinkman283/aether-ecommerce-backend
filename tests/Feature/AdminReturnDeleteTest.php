<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminReturnDeleteTest extends TestCase
{
    private function createAdmin(): User
    {
        return User::firstOrCreate(
            ['email' => 'admin_return_del@example.com'],
            [
                'name' => 'Admin Return Tester',
                'password' => bcrypt('password123'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );
    }

    private function createTestReturn(): OrderReturn
    {
        $category = Category::firstOrCreate(
            ['slug' => 'test-cat-ret'],
            ['name' => 'Test Cat Ret']
        );

        $product = Product::firstOrCreate(
            ['slug' => 'test-prod-ret'],
            [
                'category_id' => $category->id,
                'name' => 'Test Prod Ret',
                'price' => 100,
                'stock_quantity' => 10,
                'is_active' => true,
            ]
        );

        $order = Order::create([
            'order_number' => 'ORD-RET-' . uniqid(),
            'customer_name' => 'Customer Ret',
            'customer_email' => 'ret@example.com',
            'shipping_address' => '123 Ret St',
            'subtotal' => 100,
            'total_amount' => 100,
            'payment_status' => 'pending',
            'order_status' => 'confirmed',
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 100,
            'total_price' => 100,
        ]);

        $orderReturn = OrderReturn::create([
            'return_number' => 'RET-' . uniqid(),
            'order_id' => $order->id,
            'return_type' => 'rto',
            'status' => 'received',
            'inspection_status' => 'pending',
            'refund_status' => 'none',
        ]);

        OrderReturnItem::create([
            'order_return_id' => $orderReturn->id,
            'order_item_id' => $orderItem->id,
            'product_id' => $product->id,
            'quantity_returned' => 1,
            'condition' => 'unopened',
            'qc_status' => 'pending',
        ]);

        return $orderReturn;
    }

    public function test_admin_can_delete_single_return(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin, ['admin:access']);

        $return = $this->createTestReturn();
        $returnId = $return->id;

        $response = $this->deleteJson("/api/admin/returns/{$returnId}");

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
        ]);

        $this->assertDatabaseMissing('order_returns', ['id' => $returnId]);
        $this->assertDatabaseMissing('order_return_items', ['order_return_id' => $returnId]);
    }

    public function test_admin_can_bulk_delete_returns(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin, ['admin:access']);

        $ret1 = $this->createTestReturn();
        $ret2 = $this->createTestReturn();

        $ids = [$ret1->id, $ret2->id];

        $response = $this->postJson('/api/admin/returns/bulk-delete', [
            'ids' => $ids,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'deleted_count' => 2,
        ]);

        $this->assertDatabaseMissing('order_returns', ['id' => $ret1->id]);
        $this->assertDatabaseMissing('order_returns', ['id' => $ret2->id]);
    }
}
