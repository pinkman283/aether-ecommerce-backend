<?php

namespace Tests\Feature\Courier;

use Tests\TestCase;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Integration;
use App\Services\Courier\CourierManager;
use Illuminate\Support\Facades\Http;

class OrderShipmentBookingTest extends TestCase
{
    private ?Integration $integration = null;
    private ?Order $order = null;
    private CourierManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = app(CourierManager::class);

        $this->integration = Integration::updateOrCreate(
            ['provider' => 'steadfast'],
            [
                'name' => 'Steadfast Test Provider',
                'category' => 'courier',
                'status' => 'active',
                'credentials' => [
                    'api_key' => 'test_api_key',
                    'secret_key' => 'test_secret_key',
                    'base_url' => 'https://portal.steadfast.com.bd/api/v1',
                ],
            ]
        );

        Order::withTrashed()->where('order_number', 'ORD-BOOKING-001')->forceDelete();

        $this->order = Order::create([
            'order_number' => 'ORD-BOOKING-001',
            'customer_name' => 'Booking Customer',
            'customer_email' => 'booking@test.com',
            'customer_phone' => '01755555555',
            'total_amount' => 2200,
            'subtotal' => 2100,
            'shipping_cost' => 100,
            'order_status' => 'processing',
            'payment_status' => 'unpaid',
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'inside_dhaka',
            'shipping_address' => [
                'address_line1' => 'Uttara Sector 3',
                'city' => 'Dhaka',
                'postal_code' => '1230',
                'country' => 'Bangladesh',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->order) {
            $this->order->shipments()->delete();
            $this->order->forceDelete();
        }
        if ($this->integration && $this->integration->name === 'Steadfast Test Provider') {
            $this->integration->delete();
        }
        parent::tearDown();
    }

    public function test_booking_shipment_creates_consignment_and_updates_order(): void
    {
        Http::fake([
            'https://portal.steadfast.com.bd/api/v1/create_order' => Http::response([
                'status' => 200,
                'message' => 'Order created',
                'consignment' => [
                    'consignment_id' => 881122,
                    'invoice' => 'ORD-BOOKING-001',
                    'tracking_code' => 'STDF-881122',
                    'recipient_name' => 'Booking Customer',
                    'recipient_phone' => '01755555555',
                    'recipient_address' => 'Uttara Sector 3, Dhaka',
                    'cod_amount' => 2200,
                    'status' => 'in_review',
                ]
            ], 200),
        ]);

        $shipment = $this->manager->bookShipment($this->order, [
            'provider' => 'steadfast',
            'notes' => 'Test booking dispatch',
        ]);

        $this->assertInstanceOf(Shipment::class, $shipment);
        $this->assertEquals('881122', $shipment->consignment_id);
        $this->assertEquals('STDF-881122', $shipment->tracking_code);
        $this->assertEquals('booked', $shipment->status);

        $this->order->refresh();
        $this->assertEquals('shipped', $this->order->order_status);
        $this->assertEquals('Steadfast', $this->order->carrier);
        $this->assertEquals('STDF-881122', $this->order->tracking_code);
    }

    public function test_duplicate_booking_is_prevented_when_active_shipment_exists(): void
    {
        // First create an active shipment
        Shipment::create([
            'order_id' => $this->order->id,
            'provider' => 'steadfast',
            'consignment_id' => 'CID-EXISTING-1',
            'tracking_code' => 'TRK-EXISTING-1',
            'recipient_name' => 'Booking Customer',
            'recipient_phone' => '01755555555',
            'recipient_address' => 'Uttara Sector 3, Dhaka',
            'status' => 'booked',
            'weight' => 0.5,
            'cod_amount' => 2200,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already has an active consignment');

        $this->manager->bookShipment($this->order, [
            'provider' => 'steadfast',
        ]);
    }
}
