<?php

namespace Tests\Feature\Courier;

use Tests\TestCase;
use App\Services\Courier\PathaoCourierService;
use App\DTOs\Courier\ShipmentBookingDTO;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class PathaoCourierServiceTest extends TestCase
{
    private PathaoCourierService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PathaoCourierService([
            'client_id' => 'pathao_client_id_test',
            'client_secret' => 'pathao_client_secret_test',
            'client_email' => 'merchant@example.com',
            'client_password' => 'secret_pass_123',
            'base_url' => 'https://courier-api-sandbox.pathao.com',
            'webhook_secret' => 'pathao_webhook_secret_signature',
        ]);
        Cache::flush();
    }

    public function test_pathao_token_generation_and_caching(): void
    {
        Http::fake([
            'https://courier-api-sandbox.pathao.com/aladdin/api/v1/issue-token' => Http::response([
                'access_token' => 'mock_pathao_jwt_token_xyz',
                'token_type' => 'Bearer',
                'expires_in' => 86400,
            ], 200),
            'https://courier-api-sandbox.pathao.com/aladdin/api/v1/stores' => Http::response([
                'type' => 'success',
                'data' => [
                    'data' => [
                        [
                            'store_id' => 7711,
                            'store_name' => 'Main Warehouse',
                            'store_address' => 'Banani, Dhaka',
                        ]
                    ]
                ]
            ], 200),
        ]);

        $stores = $this->service->getPickupStores();

        $this->assertCount(1, $stores);
        $this->assertEquals(7711, $stores[0]['store_id']);
        $this->assertEquals('Main Warehouse', $stores[0]['store_name']);
        $this->assertTrue(Cache::has('pathao_courier_token_' . md5('pathao_client_id_test' . 'merchant@example.com_prod')));
    }

    public function test_pathao_create_shipment_successful(): void
    {
        Http::fake([
            'https://courier-api-sandbox.pathao.com/aladdin/api/v1/issue-token' => Http::response([
                'access_token' => 'mock_pathao_jwt_token_xyz',
                'token_type' => 'Bearer',
                'expires_in' => 86400,
            ], 200),
            'https://courier-api-sandbox.pathao.com/aladdin/api/v1/orders' => Http::response([
                'type' => 'success',
                'message' => 'Order created successfully',
                'data' => [
                    'consignment_id' => 'PTH-CNS-5544',
                    'order_status' => 'Pending',
                    'delivery_fee' => 60,
                ]
            ], 200),
        ]);

        $dto = new ShipmentBookingDTO(
            orderId: 202,
            invoiceNumber: 'ORD-2002',
            recipientName: 'Rahim Uddin',
            recipientPhone: '01811111111',
            recipientAddress: 'House 12, Road 4, Dhanmondi, Dhaka',
            codAmount: 1800,
            weight: 1.0,
            deliveryArea: 'Dhaka',
            pickupStoreId: '7711',
            notes: 'Test pathao dispatch'
        );

        $result = $this->service->createShipment($dto);

        $this->assertTrue($result->success);
        $this->assertEquals('PTH-CNS-5544', $result->consignmentId);
        $this->assertEquals('booked', $result->courierStatus);
        $this->assertEquals(60.0, $result->courierCharge);
    }

    public function test_pathao_webhook_signature_verification(): void
    {
        $payload = json_encode([
            'consignment_id' => 'PTH-CNS-5544',
            'order_status' => 'Delivered',
        ]);

        $validSignature = hash_hmac('sha256', $payload, 'pathao_webhook_secret_signature');

        $request = Request::create('/api/webhooks/courier/pathao', 'POST', [], [], [], [
            'HTTP_X_PATHAO_SIGNATURE' => $validSignature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertTrue($this->service->verifyWebhookSignature($request));

        $invalidRequest = Request::create('/api/webhooks/courier/pathao', 'POST', [], [], [], [
            'HTTP_X_PATHAO_SIGNATURE' => 'invalid_signature_hash',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertFalse($this->service->verifyWebhookSignature($invalidRequest));
    }
}
