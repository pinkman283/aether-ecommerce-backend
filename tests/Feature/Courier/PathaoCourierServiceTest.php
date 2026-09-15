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
                'refresh_token' => 'mock_pathao_refresh_token_abc',
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
        $this->assertTrue(Cache::has('pathao_courier_refresh_token_' . md5('pathao_client_id_test' . 'merchant@example.com_prod')));
    }

    public function test_pathao_create_shipment_successful_with_area(): void
    {
        Http::fake([
            'https://courier-api-sandbox.pathao.com/aladdin/api/v1/issue-token' => Http::response([
                'access_token' => 'mock_pathao_jwt_token_xyz',
                'token_type' => 'Bearer',
                'expires_in' => 86400,
            ], 200),
            'https://courier-api-sandbox.pathao.com/aladdin/api/v1/orders' => function (\Illuminate\Http\Client\Request $request) {
                $payload = $request->data();
                // Ensure recipient_area is explicitly included in the payload
                if (!isset($payload['recipient_area']) || $payload['recipient_area'] !== 5) {
                    return Http::response(['message' => 'Missing or invalid recipient_area in payload'], 422);
                }
                return Http::response([
                    'type' => 'success',
                    'message' => 'Order created successfully',
                    'data' => [
                        'consignment_id' => 'PTH-CNS-5544',
                        'order_status' => 'Pending',
                        'delivery_fee' => 60,
                    ]
                ], 200);
            },
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
            notes: 'Test pathao dispatch',
            recipientCityId: 1,
            recipientZoneId: 1,
            recipientAreaId: 5
        );

        $result = $this->service->createShipment($dto);

        $this->assertTrue($result->success);
        $this->assertEquals('PTH-CNS-5544', $result->consignmentId);
        $this->assertEquals('booked', $result->courierStatus);
        $this->assertEquals(60.0, $result->courierCharge);
    }

    public function test_pathao_create_shipment_fails_safely_when_city_or_zone_missing(): void
    {
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
            // recipientCityId and recipientZoneId omitted
        );

        $result = $this->service->createShipment($dto);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('explicitly selected recipient City and Zone', $result->errorMessage);
    }

    public function test_pathao_401_automatically_retries_with_fresh_token(): void
    {
        $callCount = 0;
        Http::fake([
            'https://courier-api-sandbox.pathao.com/aladdin/api/v1/issue-token' => Http::response([
                'access_token' => 'fresh_jwt_token_recovered',
                'token_type' => 'Bearer',
                'expires_in' => 86400,
            ], 200),
            'https://courier-api-sandbox.pathao.com/aladdin/api/v1/stores' => function (\Illuminate\Http\Client\Request $request) use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    // First attempt receives 401 Unauthorized (expired token)
                    return Http::response(['message' => 'Unauthenticated.'], 401);
                }
                // Second attempt receives 200 OK with fresh token
                return Http::response([
                    'type' => 'success',
                    'data' => [
                        'data' => [
                            ['store_id' => 8822, 'store_name' => 'Recovery Hub', 'store_address' => 'Tejgaon']
                        ]
                    ]
                ], 200);
            },
        ]);

        // Pre-populate with stale token
        $hash = md5('pathao_client_id_test' . 'merchant@example.com_prod');
        Cache::put('pathao_courier_token_' . $hash, 'stale_expired_token', 3600);

        $stores = $this->service->getStores();

        $this->assertCount(1, $stores);
        $this->assertEquals(8822, $stores[0]['store_id']);
        $this->assertEquals(2, $callCount); // Verified: exactly 1 retry occurred
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

    public function test_pathao_calculate_price_successful(): void
    {
        Http::fake([
            'https://courier-api-sandbox.pathao.com/aladdin/api/v1/issue-token' => Http::response([
                'access_token' => 'mock_pathao_jwt_token_xyz',
                'token_type' => 'Bearer',
                'expires_in' => 86400,
            ], 200),
            'https://courier-api-sandbox.pathao.com/aladdin/api/v1/merchant/price-plan' => function (\Illuminate\Http\Client\Request $request) {
                $payload = $request->data();
                $this->assertEquals(7711, $payload['store_id']);
                $this->assertEquals(1, $payload['recipient_city']);
                $this->assertEquals(2, $payload['recipient_zone']);
                $this->assertEquals(1.5, $payload['item_weight']);

                return Http::response([
                    'type' => 'success',
                    'message' => 'Price calculated successfully',
                    'data' => [
                        'price' => 75.0,
                        'plan' => 'Normal Delivery 48h',
                        'additional_charge' => 18.0,
                    ]
                ], 200);
            },
        ]);

        $result = $this->service->calculatePrice([
            'store_id' => 7711,
            'recipient_city' => 1,
            'recipient_zone' => 2,
            'item_weight' => 1.5,
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals(75.0, $result['delivery_charge']);
        $this->assertEquals(18.0, $result['cod_charge']);
        $this->assertEquals(93.0, $result['total_charge']);
        $this->assertEquals('Normal Delivery 48h', $result['plan']);
    }

    public function test_pathao_calculate_price_fails_safely_when_location_missing(): void
    {
        $result = $this->service->calculatePrice([
            'store_id' => 7711,
            'item_weight' => 1.0,
            // recipient_city and recipient_zone missing
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Recipient City and Zone are required', $result['message']);
    }

    public function test_pathao_calculate_price_handles_api_failure_gracefully(): void
    {
        Http::fake([
            'https://courier-api-sandbox.pathao.com/aladdin/api/v1/issue-token' => Http::response([
                'access_token' => 'mock_pathao_jwt_token_xyz',
                'token_type' => 'Bearer',
                'expires_in' => 86400,
            ], 200),
            'https://courier-api-sandbox.pathao.com/aladdin/api/v1/merchant/price-plan' => Http::response([
                'type' => 'error',
                'message' => 'Zone coverage temporarily suspended',
            ], 422),
        ]);

        $result = $this->service->calculatePrice([
            'store_id' => 7711,
            'recipient_city' => 1,
            'recipient_zone' => 2,
            'item_weight' => 1.0,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Zone coverage temporarily suspended', $result['message']);
    }
}
