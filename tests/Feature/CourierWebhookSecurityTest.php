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
        $existing = Integration::where('provider', 'pathao')->first();
        $creds = $existing ? ($existing->credentials ?? []) : [];
        $creds['webhook_secret'] = 'supersecretkey123';

        Integration::updateOrCreate(
            ['provider' => 'pathao'],
            [
                'name' => 'Pathao Courier',
                'category' => 'courier',
                'is_enabled' => true,
                'credentials' => $creds,
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

        // Restore clean credentials without webhook_secret
        $clean = Integration::where('provider', 'pathao')->first();
        if ($clean) {
            $c = $clean->credentials ?? [];
            unset($c['webhook_secret']);
            $clean->credentials = $c;
            $clean->save();
        }
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
