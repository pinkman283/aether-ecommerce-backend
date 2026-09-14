<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourierWebhookSecurityTest extends TestCase
{
    public function test_pathao_webhook_rejects_missing_or_invalid_signature(): void
    {
        Integration::updateOrCreate(
            ['provider' => 'pathao'],
            [
                'name' => 'Pathao Courier',
                'category' => 'courier',
                'is_enabled' => true,
                'credentials' => ['webhook_secret' => 'supersecretkey123'],
            ]
        );

        // Request with missing signature
        $response = $this->postJson('/api/webhooks/courier/pathao', [
            'consignment_id' => 'PTH-123456',
            'order_status' => 'Delivered',
        ]);

        $response->assertStatus(401);

        // Request with invalid signature
        $responseWithBadSig = $this->withHeaders([
            'X-Pathao-Signature' => 'invalid_signature_hash',
        ])->postJson('/api/webhooks/courier/pathao', [
            'consignment_id' => 'PTH-123456',
            'order_status' => 'Delivered',
        ]);

        $responseWithBadSig->assertStatus(401);
    }

    public function test_steadfast_webhook_rejects_invalid_token(): void
    {
        Integration::updateOrCreate(
            ['provider' => 'steadfast'],
            [
                'name' => 'Steadfast Courier',
                'category' => 'courier',
                'is_enabled' => true,
                'credentials' => ['webhook_token' => 'steadfast_secret_token_abc'],
            ]
        );

        $response = $this->withHeaders([
            'X-Steadfast-Token' => 'wrong_token',
        ])->postJson('/api/webhooks/courier/steadfast', [
            'consignment_id' => 'ST-998877',
            'status' => 'delivered',
        ]);

        $response->assertStatus(401);
    }
}
