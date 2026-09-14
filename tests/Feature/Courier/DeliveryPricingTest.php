<?php

namespace Tests\Feature\Courier;

use Tests\TestCase;
use App\Models\Product;
use App\Models\Order;

class DeliveryPricingTest extends TestCase
{
    private ?Product $product = null;
    private ?Order $createdOrder = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Create test product
        $this->product = Product::create([
            'name' => 'Shipping Rate Test Product',
            'slug' => 'shipping-rate-test-product-' . uniqid(),
            'price' => 500,
            'cost_price' => 300.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->createdOrder) {
            $this->createdOrder->items()->delete();
            $this->createdOrder->delete();
        }
        if ($this->product) {
            $this->product->delete();
        }
        parent::tearDown();
    }

    public function test_shipping_zones_endpoint_returns_configured_rates(): void
    {
        $response = $this->getJson('/api/shipping-zones');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'zones' => [
                '*' => ['id', 'name', 'rate', 'duration']
            ]
        ]);

        $zones = $response->json('zones');
        $zoneIds = array_column($zones, 'id');
        $this->assertContains('inside_dhaka', $zoneIds);
        $this->assertContains('outside_dhaka', $zoneIds);
    }

    public function test_order_creation_calculates_authoritative_shipping_for_inside_dhaka(): void
    {
        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Dhaka Buyer',
            'customer_email' => 'dhakabuyer@example.com',
            'customer_phone' => '01712345678',
            'shipping_address' => [
                'address_line1' => 'Gulshan 2',
                'city' => 'Dhaka',
                'postal_code' => '1212',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_price' => 500,
                ]
            ],
            // Malicious/tampered shipping cost passed from client
            'shipping_cost' => 5,
        ]);

        $response->assertStatus(201);
        $orderData = $response->json('data') ?? $response->json('order') ?? $response->json();
        $this->createdOrder = Order::find($orderData['id']);

        $this->assertNotNull($this->createdOrder);
        // Server authoritative inside_dhaka rate is 60, not 5
        $this->assertEquals(60.0, (float) $this->createdOrder->shipping_amount);
        // Subtotal: 2 * 500 = 1000 + 60 shipping + tax = total
        $this->assertEquals(1060.0 + (float) $this->createdOrder->tax_amount, (float) $this->createdOrder->total_amount);
    }

    public function test_order_creation_calculates_authoritative_shipping_for_outside_dhaka(): void
    {
        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Chittagong Buyer',
            'customer_email' => 'ctg_buyer@example.com',
            'customer_phone' => '01812345678',
            'shipping_address' => [
                'address_line1' => 'Agrabad Commercial Area',
                'city' => 'Chittagong',
                'postal_code' => '4000',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'outside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                ]
            ],
        ]);

        $response->assertStatus(201);
        $orderData = $response->json('data') ?? $response->json('order') ?? $response->json();
        $this->createdOrder = Order::find($orderData['id']);

        $this->assertNotNull($this->createdOrder);
        // Outside dhaka authoritative rate is 130
        $this->assertEquals(130.0, (float) $this->createdOrder->shipping_amount);
        $this->assertEquals(630.0 + (float) $this->createdOrder->tax_amount, (float) $this->createdOrder->total_amount);
    }
}
