<?php

namespace Tests\Feature\Customer;

use App\Models\Category;
use App\Models\CustomerCartItem;
use App\Models\CustomerWishlistItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerAccountStorefrontTest extends TestCase
{
    protected User $customerA;
    protected User $customerB;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::firstOrCreate(
            ['slug' => 'fashion-apparel'],
            ['name' => 'Fashion & Apparel', 'is_active' => true]
        );

        $this->customerA = User::create([
            'name' => 'Alice Customer',
            'email' => 'alice.' . uniqid() . '@example.com',
            'password' => bcrypt('Password123!'),
            'role' => 'customer',
            'customer_type' => 'registered',
            'status' => 'active',
        ]);

        $this->customerB = User::create([
            'name' => 'Bob Customer',
            'email' => 'bob.' . uniqid() . '@example.com',
            'password' => bcrypt('Password123!'),
            'role' => 'customer',
            'customer_type' => 'registered',
            'status' => 'active',
        ]);
    }

    protected function createProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Test Product ' . uniqid(),
            'slug' => 'test-product-' . uniqid(),
            'sku' => 'SKU-' . strtoupper(Str::random(6)),
            'category_id' => $this->category->id,
            'price' => 500.00,
            'stock_quantity' => 20,
            'is_active' => true,
            'status' => 'active',
        ], $attributes));
    }

    protected function createVariant(Product $product, array $attributes = []): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'name' => 'Variant ' . uniqid(),
            'sku' => 'VAR-' . strtoupper(Str::random(6)),
            'color_name' => 'Black',
            'size' => 'M',
            'price_modifier' => 50.00,
            'stock_quantity' => 15,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * 1. Customer can access own cart.
     */
    public function test_01_customer_can_access_own_cart(): void
    {
        $product = $this->createProduct(['price' => 250.00]);
        CustomerCartItem::create([
            'user_id' => $this->customerA->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        Sanctum::actingAs($this->customerA, ['customer:access']);

        $response = $this->getJson('/api/customer/cart');
        $response->assertStatus(200);
        $response->assertJsonPath('summary.total_items', 1);
        $response->assertJsonPath('summary.total_quantity', 2);
        $response->assertJsonPath('items.0.product_id', $product->id);
        $this->assertEquals(500.00, (float) $response->json('items.0.total_price'));
    }

    /**
     * 2. Customer cannot access another customer's cart.
     */
    public function test_02_customer_cannot_access_another_customers_cart(): void
    {
        $product = $this->createProduct();
        CustomerCartItem::create([
            'user_id' => $this->customerA->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        // Acting as customer B
        Sanctum::actingAs($this->customerB, ['customer:access']);

        $response = $this->getJson('/api/customer/cart');
        $response->assertStatus(200);
        $response->assertJsonPath('summary.total_items', 0);
        $response->assertJsonPath('items', []);
    }

    /**
     * 3. Customer can access own wishlist.
     */
    public function test_03_customer_can_access_own_wishlist(): void
    {
        $product = $this->createProduct();
        CustomerWishlistItem::create([
            'user_id' => $this->customerA->id,
            'product_id' => $product->id,
        ]);

        Sanctum::actingAs($this->customerA, ['customer:access']);

        $response = $this->getJson('/api/customer/wishlist');
        $response->assertStatus(200);
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('products.0.id', $product->id);
    }

    /**
     * 4. Customer cannot access another customer's wishlist.
     */
    public function test_04_customer_cannot_access_another_customers_wishlist(): void
    {
        $product = $this->createProduct();
        CustomerWishlistItem::create([
            'user_id' => $this->customerA->id,
            'product_id' => $product->id,
        ]);

        Sanctum::actingAs($this->customerB, ['customer:access']);

        $response = $this->getJson('/api/customer/wishlist');
        $response->assertStatus(200);
        $response->assertJsonPath('total', 0);
        $response->assertJsonPath('products', []);
    }

    /**
     * 5. Customer can access own orders.
     */
    public function test_05_customer_can_access_own_orders(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-OWN-' . uniqid(),
            'user_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'customer_email' => $this->customerA->email,
            'subtotal' => 500.00,
            'total_amount' => 560.00,
            'shipping_amount' => 60.00,
            'discount_amount' => 0.00,
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'order_status' => 'pending',
            'shipping_address' => ['city' => 'Dhaka'],
        ]);

        Sanctum::actingAs($this->customerA, ['customer:access']);

        // Test index endpoint
        $response = $this->getJson('/api/orders');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertEquals($order->order_number, $data[0]['order_number']);

        // Test show endpoint
        $showResponse = $this->getJson('/api/orders/' . $order->order_number);
        $showResponse->assertStatus(200);
        $showResponse->assertJsonPath('order_number', $order->order_number);
    }

    /**
     * 6. Customer cannot access another customer's orders.
     */
    public function test_06_customer_cannot_access_another_customers_orders(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-PRIVATE-' . uniqid(),
            'user_id' => $this->customerA->id,
            'customer_name' => $this->customerA->name,
            'customer_email' => $this->customerA->email,
            'subtotal' => 1000.00,
            'total_amount' => 1060.00,
            'shipping_amount' => 60.00,
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'order_status' => 'pending',
            'shipping_address' => ['city' => 'Dhaka'],
        ]);

        // Customer B attempts to access Customer A's order by number
        Sanctum::actingAs($this->customerB, ['customer:access']);

        $response = $this->getJson('/api/orders/' . $order->order_number);
        $response->assertStatus(403);
        $this->assertStringContainsString('Access Denied', $response->json('message'));

        // Customer B also cannot see Customer A's order in orders index
        $indexResponse = $this->getJson('/api/orders');
        $indexResponse->assertStatus(200);
        $this->assertEmpty($indexResponse->json('data'));

        // Unauthenticated guest cannot access registered customer's order
        auth()->forgetGuards();
        $guestResponse = $this->getJson('/api/orders/' . $order->order_number);
        $guestResponse->assertStatus(403);
    }

    /**
     * 7. Guest cart merge upon login.
     */
    public function test_07_guest_cart_merge(): void
    {
        $product = $this->createProduct(['price' => 400.00, 'stock_quantity' => 10]);

        Sanctum::actingAs($this->customerA, ['customer:access']);

        $response = $this->postJson('/api/customer/cart/merge', [
            'guest_items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('summary.total_items', 1);
        $response->assertJsonPath('summary.total_quantity', 2);
        $response->assertJsonPath('items.0.product_id', $product->id);
        $this->assertDatabaseHas('customer_cart_items', [
            'user_id' => $this->customerA->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
    }

    /**
     * 8. Duplicate cart item merge: combines quantities without duplicate rows.
     */
    public function test_08_duplicate_cart_item_merge(): void
    {
        $product = $this->createProduct(['price' => 300.00, 'stock_quantity' => 15]);

        // Server already has 2 of this product
        CustomerCartItem::create([
            'user_id' => $this->customerA->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        Sanctum::actingAs($this->customerA, ['customer:access']);

        // Guest had 3 of the same product
        $response = $this->postJson('/api/customer/cart/merge', [
            'guest_items' => [
                ['product_id' => $product->id, 'quantity' => 3],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('summary.total_items', 1);
        $response->assertJsonPath('summary.total_quantity', 5); // 2 + 3 = 5

        // Verify only one row exists in database
        $this->assertEquals(1, CustomerCartItem::where('user_id', $this->customerA->id)->count());
        $this->assertDatabaseHas('customer_cart_items', [
            'user_id' => $this->customerA->id,
            'product_id' => $product->id,
            'quantity' => 5,
        ]);
    }

    /**
     * 9. Stock-limited merge: combined quantity capped at available stock.
     */
    public function test_09_stock_limited_merge(): void
    {
        $product = $this->createProduct(['price' => 500.00, 'stock_quantity' => 4]);

        // Server has 3
        CustomerCartItem::create([
            'user_id' => $this->customerA->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        Sanctum::actingAs($this->customerA, ['customer:access']);

        // Guest has 2 (Total requested = 5, but stock is only 4)
        $response = $this->postJson('/api/customer/cart/merge', [
            'guest_items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('summary.total_quantity', 4);
        $this->assertDatabaseHas('customer_cart_items', [
            'user_id' => $this->customerA->id,
            'product_id' => $product->id,
            'quantity' => 4,
        ]);
    }

    /**
     * 10. Invalid/deleted product cleanup during cart access.
     */
    public function test_10_invalid_or_deleted_product_cleanup(): void
    {
        $product = $this->createProduct(['is_active' => false]); // Inactive

        CustomerCartItem::create([
            'user_id' => $this->customerA->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        Sanctum::actingAs($this->customerA, ['customer:access']);

        $response = $this->getJson('/api/customer/cart');
        $response->assertStatus(200);
        $response->assertJsonPath('summary.total_items', 0);
        $this->assertDatabaseMissing('customer_cart_items', [
            'user_id' => $this->customerA->id,
            'product_id' => $product->id,
        ]);
    }

    /**
     * 11. Variant validation: ensures variant belongs to product.
     */
    public function test_11_variant_validation(): void
    {
        $productA = $this->createProduct();
        $productB = $this->createProduct();
        $variantB = $this->createVariant($productB); // Belongs to B, not A

        CustomerCartItem::create([
            'user_id' => $this->customerA->id,
            'product_id' => $productA->id,
            'variant_id' => $variantB->id,
            'quantity' => 1,
        ]);

        Sanctum::actingAs($this->customerA, ['customer:access']);

        $response = $this->getJson('/api/customer/cart');
        $response->assertStatus(200);
        $response->assertJsonPath('summary.total_items', 0);
        $this->assertDatabaseMissing('customer_cart_items', [
            'user_id' => $this->customerA->id,
            'product_id' => $productA->id,
        ]);
    }

    /**
     * 12. Authoritative pricing refresh: uses current product price and variant modifier.
     */
    public function test_12_authoritative_pricing_refresh(): void
    {
        $product = $this->createProduct(['price' => 700.00]);
        $variant = $this->createVariant($product, ['price_modifier' => 150.00]);

        CustomerCartItem::create([
            'user_id' => $this->customerA->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);

        Sanctum::actingAs($this->customerA, ['customer:access']);

        $response = $this->getJson('/api/customer/cart');
        $response->assertStatus(200);
        // Unit price = 700 + 150 = 850
        $this->assertEquals(850.00, (float) $response->json('items.0.unit_price'));
        // Total price = 850 * 2 = 1700
        $this->assertEquals(1700.00, (float) $response->json('items.0.total_price'));
        $this->assertEquals(1700.00, (float) $response->json('summary.subtotal'));
    }

    /**
     * 13. Wishlist merge: merges guest product IDs with customer wishlist.
     */
    public function test_13_wishlist_merge(): void
    {
        $product1 = $this->createProduct();
        $product2 = $this->createProduct();

        // Customer already has product1 in server wishlist
        CustomerWishlistItem::create([
            'user_id' => $this->customerA->id,
            'product_id' => $product1->id,
        ]);

        Sanctum::actingAs($this->customerA, ['customer:access']);

        // Merge guest wishlist containing product1 (duplicate) and product2 (new)
        $response = $this->postJson('/api/customer/wishlist/merge', [
            'guest_product_ids' => [$product1->id, $product2->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('total', 2);
        $this->assertEquals(2, CustomerWishlistItem::where('user_id', $this->customerA->id)->count());
    }

    /**
     * 14. Authentication transition: customer cart remains safe after logout.
     */
    public function test_14_authentication_transition(): void
    {
        $product = $this->createProduct();
        CustomerCartItem::create([
            'user_id' => $this->customerA->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        Sanctum::actingAs($this->customerA, ['customer:access']);

        // Logout
        $logoutResponse = $this->postJson('/api/auth/logout');
        $logoutResponse->assertStatus(200);

        // Verify cart is NOT deleted on logout
        $this->assertDatabaseHas('customer_cart_items', [
            'user_id' => $this->customerA->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);
    }

    /**
     * 15. Unauthorized access: unauthenticated requests to customer cart/wishlist are rejected.
     */
    public function test_15_unauthorized_access(): void
    {
        $this->getJson('/api/customer/cart')->assertStatus(401);
        $this->postJson('/api/customer/cart/sync', ['items' => []])->assertStatus(401);
        $this->postJson('/api/customer/cart/merge', ['guest_items' => []])->assertStatus(401);
        $this->deleteJson('/api/customer/cart')->assertStatus(401);

        $this->getJson('/api/customer/wishlist')->assertStatus(401);
        $this->postJson('/api/customer/wishlist/toggle', ['product_id' => 1])->assertStatus(401);
        $this->postJson('/api/customer/wishlist/merge', ['guest_product_ids' => []])->assertStatus(401);
        $this->deleteJson('/api/customer/wishlist')->assertStatus(401);
    }

    /**
     * 16. Existing checkout and order regression: customer can still checkout via COD.
     */
    public function test_16_existing_checkout_and_order_regression(): void
    {
        $product = $this->createProduct(['price' => 600.00, 'stock_quantity' => 10]);

        Sanctum::actingAs($this->customerA, ['customer:access']);

        $response = $this->postJson('/api/orders', [
            'customer_name' => $this->customerA->name,
            'customer_email' => $this->customerA->email,
            'customer_phone' => '01811223344',
            'shipping_address' => [
                'address_line1' => 'Gulshan 2',
                'city' => 'Dhaka',
                'postal_code' => '1212',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka', // 60.00 BDT
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);
        $order = $response->json('order');
        $this->assertEquals($this->customerA->id, $order['user_id']);
        $this->assertEquals('cash_on_delivery', $order['payment_method']);
        $this->assertEquals('pending', $order['payment_status']);
        $this->assertEquals(660.00, (float) $order['total_amount']);
    }
}
