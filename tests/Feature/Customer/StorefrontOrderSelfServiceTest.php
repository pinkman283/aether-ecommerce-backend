<?php

namespace Tests\Feature\Customer;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StorefrontOrderSelfServiceTest extends TestCase
{
    protected User $customerA;
    protected User $customerB;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::firstOrCreate(
            ['slug' => 'tech-gadgets'],
            ['name' => 'Tech & Gadgets', 'is_active' => true]
        );

        $this->customerA = User::create([
            'name' => 'Alice Runner',
            'email' => 'alice.' . uniqid() . '@example.com',
            'password' => bcrypt('Secret123!'),
            'role' => 'customer',
            'customer_type' => 'registered',
            'status' => 'active',
        ]);

        $this->customerB = User::create([
            'name' => 'Bob Runner',
            'email' => 'bob.' . uniqid() . '@example.com',
            'password' => bcrypt('Secret123!'),
            'role' => 'customer',
            'customer_type' => 'registered',
            'status' => 'active',
        ]);
    }

    protected function createProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Self-Service Gadget ' . uniqid(),
            'slug' => 'self-service-gadget-' . uniqid(),
            'sku' => 'SSG-' . strtoupper(Str::random(6)),
            'category_id' => $this->category->id,
            'price' => 120.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ], $attributes));
    }

    public function test_customer_can_cancel_their_own_pending_order_and_inventory_is_restored(): void
    {
        Sanctum::actingAs($this->customerA, ['customer:access']);

        $product = $this->createProduct(['stock_quantity' => 5]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant One',
            'sku' => 'SSG-V1-' . strtoupper(Str::random(4)),
            'price' => 120.00,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $orderNumber = 'ORD-TEST-' . strtoupper(Str::random(8));
        $order = Order::create([
            'order_number' => $orderNumber,
            'user_id' => $this->customerA->id,
            'customer_email' => $this->customerA->email,
            'customer_name' => $this->customerA->name,
            'total_amount' => 120.00,
            'subtotal' => 120.00,
            'order_status' => 'pending',
            'payment_status' => 'pending',
            'payment_method' => 'cod',
            'shipping_address' => ['address' => '123 Test St'],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'product_name' => $product->name,
            'variant_name' => $variant->name,
            'quantity' => 2,
            'unit_price' => 120.00,
            'total_price' => 240.00,
        ]);

        // Customer cancels order
        $response = $this->postJson("/api/orders/{$orderNumber}/cancel");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'order_status' => 'cancelled',
            ]);

        $this->assertEquals('cancelled', $order->fresh()->order_status);
        $this->assertEquals(7, $product->fresh()->stock_quantity); // 5 + 2
        $this->assertEquals(7, $variant->fresh()->stock_quantity); // 5 + 2
    }

    public function test_customer_cannot_cancel_non_pending_order(): void
    {
        Sanctum::actingAs($this->customerA, ['customer:access']);

        $orderNumber = 'ORD-PROC-' . strtoupper(Str::random(8));
        Order::create([
            'order_number' => $orderNumber,
            'user_id' => $this->customerA->id,
            'customer_email' => $this->customerA->email,
            'customer_name' => $this->customerA->name,
            'total_amount' => 120.00,
            'subtotal' => 120.00,
            'order_status' => 'processing',
            'payment_status' => 'pending',
            'payment_method' => 'cod',
            'shipping_address' => ['address' => '123 Test St'],
        ]);

        $response = $this->postJson("/api/orders/{$orderNumber}/cancel");

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_customer_cannot_cancel_another_customers_order(): void
    {
        Sanctum::actingAs($this->customerB, ['customer:access']);

        $orderNumber = 'ORD-ALICE-' . strtoupper(Str::random(8));
        Order::create([
            'order_number' => $orderNumber,
            'user_id' => $this->customerA->id,
            'customer_email' => $this->customerA->email,
            'customer_name' => $this->customerA->name,
            'total_amount' => 120.00,
            'subtotal' => 120.00,
            'order_status' => 'pending',
            'payment_status' => 'pending',
            'payment_method' => 'cod',
            'shipping_address' => ['address' => '123 Test St'],
        ]);

        $response = $this->postJson("/api/orders/{$orderNumber}/cancel");

        $response->assertStatus(403);
    }

    public function test_product_search_matches_category_and_subcategory_names(): void
    {
        $uniqueCategoryName = 'SpecialCategory' . Str::random(5);
        $category = Category::create([
            'name' => $uniqueCategoryName,
            'slug' => Str::slug($uniqueCategoryName),
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Unrelated Name ' . uniqid(),
            'slug' => 'unrelated-name-' . uniqid(),
            'sku' => 'UNR-' . strtoupper(Str::random(6)),
            'category_id' => $category->id,
            'price' => 99.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/products?search={$uniqueCategoryName}");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $ids = array_column($data, 'id');
        $this->assertContains($product->id, $ids);
    }
}
