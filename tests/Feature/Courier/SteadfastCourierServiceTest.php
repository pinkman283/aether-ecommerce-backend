<?php

namespace Tests\Feature\Courier;

use Tests\TestCase;
use App\Services\Courier\SteadfastCourierService;
use App\DTOs\Courier\ShipmentBookingDTO;
use Illuminate\Support\Facades\Http;

class SteadfastCourierServiceTest extends TestCase
{
    private SteadfastCourierService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SteadfastCourierService([
            'api_key' => 'test_api_key_123',
            'secret_key' => 'test_secret_key_456',
            'base_url' => 'https://portal.steadfast.com.bd/api/v1',
        ]);
    }

    public function test_steadfast_balance_check_successful(): void
    {
        Http::fake([
            'https://portal.steadfast.com.bd/api/v1/get_balance' => Http::response([
                'status' => 200,
                'current_balance' => 4500.50,
            ], 200),
        ]);

        $result = $this->service->testConnection();

        $this->assertTrue($result['success']);
        $this->assertEquals(4500.50, $result['balance']);
        $this->assertStringContainsString('Steadfast connected successfully', $result['message']);
    }

    public function test_steadfast_create_shipment_successful(): void
    {
        Http::fake([
            'https://portal.steadfast.com.bd/api/v1/create_order' => Http::response([
                'status' => 200,
                'message' => 'Order created successfully',
                'consignment' => [
                    'consignment_id' => 987654,
                    'invoice' => 'ORD-1001',
                    'tracking_code' => 'STDF-TRACK-9988',
                    'recipient_name' => 'John Doe',
                    'recipient_phone' => '01700000000',
                    'recipient_address' => 'Mirpur-10, Dhaka',
                    'cod_amount' => 1250,
                    'status' => 'in_review',
                ],
            ], 200),
        ]);

        $dto = new ShipmentBookingDTO(
            orderId: 101,
            invoiceNumber: 'ORD-1001',
            recipientName: 'John Doe',
            recipientPhone: '01700000000',
            recipientAddress: 'Mirpur-10, Dhaka',
            codAmount: 1250,
            weight: 0.5,
            deliveryArea: 'Dhaka',
            notes: 'Fragile - handle with care'
        );

        $result = $this->service->createShipment($dto);

        $this->assertTrue($result->success);
        $this->assertEquals('987654', $result->consignmentId);
        $this->assertEquals('STDF-TRACK-9988', $result->trackingCode);
        $this->assertEquals('booked', $result->courierStatus);
        $this->assertStringContainsString('STDF-TRACK-9988', $result->trackingUrl);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://portal.steadfast.com.bd/api/v1/create_order'
                && $request->header('Api-Key')[0] === 'test_api_key_123'
                && $request->header('Secret-Key')[0] === 'test_secret_key_456'
                && $request['invoice'] === 'ORD-1001'
                && $request['cod_amount'] == 1250;
        });
    }

    public function test_steadfast_track_shipment_maps_delivered_status(): void
    {
        Http::fake([
            'https://portal.steadfast.com.bd/api/v1/status_by_cid/987654' => Http::response([
                'status' => 200,
                'delivery_status' => 'delivered',
            ], 200),
        ]);

        $tracking = $this->service->trackShipment('987654');

        $this->assertTrue($tracking->success);
        $this->assertEquals('delivered', $tracking->normalizedStatus);
        $this->assertEquals('delivered', $tracking->courierRawStatus);
    }
}
