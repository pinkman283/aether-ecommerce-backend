<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Tests\TestCase;

class OrderCheckoutTest extends TestCase
{
    public function test_checkout_rejects_fake_online_payment_methods(): void
    {
        $category = Category::firstOrCreate(['slug' => 'test-cat'], ['name' => 'Test Category']);
        $product = Product::create([
            'name' => 'Gaming Mouse',
            'slug' => 'mouse-' . uniqid(),
            'sku' => 'MS-' . uniqid(),
            'price' => 500.00,
            'stock_quantity' => 10,
            'description' => 'Test mouse',
            'category_id' => $category->id,
        ]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Test Customer',
            'customer_email' => 'test@example.com',
            'customer_phone' => '01811223344',
            'shipping_address' => [
                'address_line1' => 'Dhanmondi 27',
                'city' => 'Dhaka',
                'postal_code' => '1209',
                'country' => 'Bangladesh',
            ],
            'payment_method' => 'credit_card',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payment_method']);
    }

    public function test_checkout_succeeds_with_cod_and_decrements_inventory(): void
    {
        $category = Category::firstOrCreate(['slug' => 'test-cat-2'], ['name' => 'Test Category 2']);
        $product = Product::create([
            'name' => 'Mechanical Numpad',
            'slug' => 'numpad-' . uniqid(),
            'sku' => 'NP-' . uniqid(),
            'price' => 800.00,
            'cost_price' => 500.00,
            'stock_quantity' => 20,
            'description' => 'Test numpad',
            'category_id' => $category->id,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'RGB Black',
            'sku' => $product->sku . '-BLK',
            'price_modifier' => 50.00,
            'cost_price' => 500.00,
            'stock_quantity' => 15,
        ]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Kamal Hossain',
            'customer_email' => 'kamal@example.com',
            'customer_phone' => '01911223344',
            'shipping_address' => [
                'address_line1' => 'Mirpur 10',
                'city' => 'Dhaka',
                'postal_code' => '1216',
                'country' => 'Bangladesh',
            ],
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(201);
        $orderData = $response->json('order');

        $this->assertEquals('cash_on_delivery', $orderData['payment_method']);
        $this->assertEquals('pending', $orderData['payment_status']);
        $this->assertNull($orderData['payment_transaction_id'], 'COD order must not have fake transaction ID.');

        // Verify inventory decrement
        $this->assertEquals(18, $product->fresh()->stock_quantity);
        $this->assertEquals(13, $variant->fresh()->stock_quantity);
    }
}
