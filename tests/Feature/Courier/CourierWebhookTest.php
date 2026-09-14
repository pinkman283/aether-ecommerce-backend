<?php

namespace Tests\Feature\Courier;

use Tests\TestCase;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Integration;
use App\Models\CourierWebhookLog;

class CourierWebhookTest extends TestCase
{
    private ?Integration $integration = null;
    private ?Order $order = null;
    private ?Shipment $shipment = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Create or update Steadfast test integration
        $this->integration = Integration::updateOrCreate(
            ['provider' => 'steadfast'],
            [
                'name' => 'Steadfast Courier Test',
                'category' => 'courier',
                'status' => 'active',
                'credentials' => [
                    'api_key' => 'webhook_test_api_key',
                    'secret_key' => 'webhook_test_secret_key',
                    'base_url' => 'https://portal.steadfast.com.bd/api/v1',
                ],
            ]
        );

        // Clean up previous test artifacts if any
        Order::withTrashed()->where('order_number', 'ORD-TEST-WH-001')->forceDelete();
        Shipment::where('consignment_id', 'CID-WH-99001')->delete();
        CourierWebhookLog::where('consignment_id', 'CID-WH-99001')->delete();

        // Create a test order
        $this->order = Order::create([
            'order_number' => 'ORD-TEST-WH-001',
            'customer_name' => 'Webhook Tester',
            'customer_email' => 'webhook@test.com',
            'customer_phone' => '01799999999',
            'total_amount' => 1500,
            'subtotal' => 1400,
            'shipping_cost' => 100,
            'order_status' => 'shipped',
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'shipping_method' => 'inside_dhaka',
            'shipping_address' => [
                'address_line1' => 'Dhanmondi, Dhaka',
                'city' => 'Dhaka',
                'country' => 'Bangladesh',
            ],
        ]);

        // Create a test shipment for this order
        $this->shipment = Shipment::create([
            'order_id' => $this->order->id,
            'provider' => 'steadfast',
            'consignment_id' => 'CID-WH-99001',
            'tracking_code' => 'TRK-WH-99001',
            'status' => 'booked',
            'recipient_name' => 'Webhook Tester',
            'recipient_phone' => '01799999999',
            'recipient_address' => 'Dhanmondi, Dhaka',
            'weight' => 0.5,
            'cod_amount' => 1500,
            'delivery_fee' => 60,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->shipment) {
            CourierWebhookLog::where('consignment_id', $this->shipment->consignment_id)->delete();
            $this->shipment->delete();
        }
        if ($this->order) {
            $this->order->delete();
        }
        if ($this->integration && $this->integration->name === 'Steadfast Courier Test') {
            $this->integration->delete();
        }

        parent::tearDown();
    }

    public function test_steadfast_webhook_rejects_invalid_secret_key(): void
    {
        $response = $this->postJson('/api/webhooks/courier/steadfast', [
            'consignment_id' => 'CID-WH-99001',
            'status' => 'delivered',
        ], [
            'Secret-Key' => 'wrong_secret_key',
        ]);

        $response->assertStatus(401);
    }

    public function test_steadfast_webhook_updates_shipment_and_order_to_delivered(): void
    {
        $response = $this->postJson('/api/webhooks/courier/steadfast', [
            'consignment_id' => 'CID-WH-99001',
            'status' => 'delivered',
            'delivery_fee' => 60,
            'cod_amount' => 1500,
        ], [
            'Secret-Key' => 'webhook_test_secret_key',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        $this->shipment->refresh();
        $this->assertEquals('delivered', $this->shipment->status);
        $this->assertNotNull($this->shipment->delivered_at);

        $this->order->refresh();
        $this->assertEquals('delivered', $this->order->order_status);
        $this->assertEquals('paid', $this->order->payment_status);

        // Verify audit log exists in courier_webhook_logs
        $this->assertDatabaseHas('courier_webhook_logs', [
            'provider' => 'steadfast',
            'consignment_id' => 'CID-WH-99001',
            'status' => 'processed',
            'event_type' => 'delivered',
        ]);
    }

    public function test_steadfast_webhook_idempotency_prevents_duplicate_processing(): void
    {
        // First delivery event
        $this->postJson('/api/webhooks/courier/steadfast', [
            'consignment_id' => 'CID-WH-99001',
            'status' => 'delivered',
        ], [
            'Secret-Key' => 'webhook_test_secret_key',
        ]);

        // Duplicate replay within sliding window
        $duplicateResponse = $this->postJson('/api/webhooks/courier/steadfast', [
            'consignment_id' => 'CID-WH-99001',
            'status' => 'delivered',
        ], [
            'Secret-Key' => 'webhook_test_secret_key',
        ]);

        $duplicateResponse->assertStatus(200);
        $duplicateResponse->assertJson([
            'status' => 'duplicate',
            'message' => 'Event already processed',
        ]);
    }
}
