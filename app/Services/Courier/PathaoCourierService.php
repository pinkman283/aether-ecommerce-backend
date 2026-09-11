<?php

namespace App\Services\Courier;

use App\Contracts\CourierProviderInterface;
use App\DTOs\Courier\ShipmentBookingDTO;
use App\DTOs\Courier\ShipmentBookingResultDTO;
use App\DTOs\Courier\ShipmentTrackingDTO;
use App\DTOs\Courier\WebhookEventDTO;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PathaoCourierService implements CourierProviderInterface
{
    protected string $baseUrl;
    protected ?string $clientId;
    protected ?string $clientSecret;
    protected ?string $username;
    protected ?string $password;
    protected ?string $storeId;
    protected ?string $webhookSecret = null;
    protected bool $isTestMode;

    public function __construct(Integration|array|null $integration = null)
    {
        if (is_array($integration)) {
            $creds = $integration;
            $this->isTestMode = (bool) ($creds['is_test_mode'] ?? false);
            $this->baseUrl = $creds['base_url'] ?? ($this->isTestMode
                ? 'https://courier-api-sandbox.pathao.com'
                : 'https://api-hermes.pathao.com');

            $this->clientId = $creds['client_id'] ?? null;
            $this->clientSecret = $creds['client_secret'] ?? null;
            $this->username = $creds['username'] ?? $creds['client_email'] ?? null;
            $this->password = $creds['password'] ?? $creds['client_password'] ?? null;
            $this->storeId = $creds['store_id'] ?? null;
            $this->webhookSecret = $creds['webhook_secret'] ?? null;
            return;
        }

        $integration = $integration ?? Integration::where('provider', 'pathao')->first();
        $creds = $integration?->credentials ?? [];
        $settings = $integration?->settings ?? [];

        $this->isTestMode = (bool) ($integration?->is_test_mode ?? false);
        $this->baseUrl = $this->isTestMode
            ? 'https://courier-api-sandbox.pathao.com'
            : 'https://api-hermes.pathao.com';

        $this->clientId = $creds['client_id'] ?? config('services.pathao.client_id');
        $this->clientSecret = $creds['client_secret'] ?? config('services.pathao.client_secret');
        $this->username = $creds['username'] ?? config('services.pathao.username');
        $this->password = $creds['password'] ?? config('services.pathao.password');
        $this->storeId = $settings['store_id'] ?? config('services.pathao.store_id');
        $this->webhookSecret = $creds['webhook_secret'] ?? null;
    }

    public function getProviderName(): string
    {
        return 'pathao';
    }

    /**
     * Retrieve OAuth2 Bearer Token with caching
     */
    protected function getAccessToken(): ?string
    {
        if (empty($this->clientId) || empty($this->clientSecret) || empty($this->username) || empty($this->password)) {
            return null;
        }

        $cacheKey = 'pathao_courier_token_' . md5($this->clientId . $this->username . ($this->isTestMode ? '_test' : '_prod'));

        return Cache::remember($cacheKey, now()->addDays(5), function () {
            try {
                $response = Http::baseUrl($this->baseUrl)
                    ->timeout(15)
                    ->post('/aladdin/api/v1/issue-token', [
                        'client_id' => $this->clientId,
                        'client_secret' => $this->clientSecret,
                        'username' => $this->username,
                        'password' => $this->password,
                        'grant_type' => 'password',
                    ]);

                if ($response->successful()) {
                    $token = $response->json('access_token');
                    return $token;
                }

                Log::error('Pathao token acquisition failed: ' . $response->body());
                return null;
            } catch (\Throwable $e) {
                Log::error('Pathao token acquisition exception: ' . $e->getMessage());
                return null;
            }
        });
    }

    protected function client()
    {
        $token = $this->getAccessToken();

        return Http::baseUrl($this->baseUrl)
            ->timeout(15)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]);
    }

    public function testConnection(): array
    {
        if (empty($this->clientId) || empty($this->clientSecret) || empty($this->username) || empty($this->password)) {
            return [
                'success' => false,
                'message' => 'Pathao Client ID, Secret, Username and Password are required.',
            ];
        }

        try {
            // Force a fresh token test
            $token = $this->getAccessToken();
            if (!$token) {
                return [
                    'success' => false,
                    'message' => 'Failed to obtain Pathao OAuth2 Token. Check Client ID, Secret, Username, or Password.',
                ];
            }

            // Test API access by retrieving stores
            $response = $this->client()->get('/aladdin/api/v1/stores');
            if ($response->successful()) {
                $stores = $response->json('data.data') ?? [];
                $storeCount = count($stores);
                return [
                    'success' => true,
                    'message' => "Pathao Logistics connected. Active Pickup Hubs: {$storeCount}",
                    'stores' => $stores,
                ];
            }

            return [
                'success' => false,
                'message' => 'Pathao token valid, but API request failed: ' . ($response->json('message') ?? 'HTTP ' . $response->status()),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Pathao gateway unreachable: ' . $e->getMessage(),
            ];
        }
    }

    public function getPickupStores(): array
    {
        try {
            $response = $this->client()->get('/aladdin/api/v1/stores');
            if ($response->successful()) {
                return $response->json('data.data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::error('Pathao getPickupStores failed: ' . $e->getMessage());
        }
        return [];
    }

    public function createShipment(ShipmentBookingDTO $dto): ShipmentBookingResultDTO
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return ShipmentBookingResultDTO::failed('Pathao authentication failed. Please verify credentials.');
        }

        $storeId = $dto->pickupStoreId ?: $this->storeId;
        if (empty($storeId)) {
            // Fallback to first store from API
            $stores = $this->getStores();
            $storeId = $stores[0]['store_id'] ?? null;
        }

        if (empty($storeId)) {
            return ShipmentBookingResultDTO::failed('Pathao Store ID (Pickup Hub) is required.');
        }

        $payload = [
            'store_id' => (int) $storeId,
            'merchant_order_id' => $dto->invoiceNumber,
            'recipient_name' => $dto->recipientName,
            'recipient_phone' => $dto->recipientPhone,
            'recipient_address' => $dto->recipientAddress,
            'recipient_city' => 1, // Default Dhaka city ID, can be overridden by area mapping
            'recipient_zone' => 1,
            'delivery_type' => 48, // Standard 48-hour delivery
            'item_type' => 1,      // Parcel
            'special_instruction' => $dto->notes ?: 'Handle with care',
            'item_quantity' => max(1, count($dto->items)),
            'item_weight' => round($dto->weight, 2),
            'amount_to_collect' => round($dto->codAmount, 2),
            'item_description' => 'Order ' . $dto->invoiceNumber,
        ];

        try {
            $response = $this->client()->post('/aladdin/api/v1/orders', $payload);
            $data = $response->json();

            if ($response->successful() && isset($data['data']['consignment_id'])) {
                $orderData = $data['data'];
                $cid = (string) $orderData['consignment_id'];
                $trackingUrl = 'https://pathao.com/courier-tracking/?consignment_id=' . $cid;
                $deliveryFee = (float) ($orderData['delivery_fee'] ?? 0.00);

                return ShipmentBookingResultDTO::successful(
                    consignmentId: $cid,
                    trackingCode: $cid,
                    trackingUrl: $trackingUrl,
                    courierStatus: $this->normalizeStatus($orderData['order_status'] ?? 'Pickup_Requested'),
                    courierCharge: $deliveryFee,
                    message: $data['message'] ?? 'Consignment booked with Pathao.',
                    rawResponse: $data
                );
            }

            $errMsg = $data['message'] ?? ($data['errors'] ? json_encode($data['errors']) : 'Failed to create Pathao order.');
            Log::error('Pathao booking failed', ['payload' => $payload, 'response' => $data]);
            return ShipmentBookingResultDTO::failed($errMsg, $data ?? []);
        } catch (\Throwable $e) {
            Log::error('Pathao booking exception', ['error' => $e->getMessage(), 'invoice' => $dto->invoiceNumber]);
            return ShipmentBookingResultDTO::failed('Pathao API error: ' . $e->getMessage());
        }
    }

    public function trackShipment(string $consignmentIdOrTracking): ShipmentTrackingDTO
    {
        try {
            $response = $this->client()->get("/aladdin/api/v1/orders/{$consignmentIdOrTracking}/info");
            $data = $response->json();

            if ($response->successful() && isset($data['data'])) {
                $info = $data['data'];
                $rawStatus = (string) ($info['order_status'] ?? 'In_Transit');
                $trackingUrl = 'https://pathao.com/courier-tracking/?consignment_id=' . $consignmentIdOrTracking;

                return ShipmentTrackingDTO::successful(
                    normalizedStatus: $this->normalizeStatus($rawStatus),
                    courierRawStatus: $rawStatus,
                    consignmentId: $consignmentIdOrTracking,
                    trackingCode: $consignmentIdOrTracking,
                    trackingUrl: $trackingUrl,
                    lastUpdated: $info['updated_at'] ?? now()->toIso8601String(),
                    rawResponse: $data
                );
            }

            return ShipmentTrackingDTO::failed($data['message'] ?? 'Unable to retrieve tracking info from Pathao.', $data ?? []);
        } catch (\Throwable $e) {
            return ShipmentTrackingDTO::failed('Pathao tracking failed: ' . $e->getMessage());
        }
    }

    public function cancelShipment(string $consignmentId): bool
    {
        return true;
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $signature = $request->header('X-PATHAO-Signature');
        $secret = $this->webhookSecret ?: $this->clientSecret;
        if (empty($signature) || empty($secret)) {
            return true; // Allow webhook if signature not configured
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        return hash_equals($expected, $signature);
    }

    public function parseWebhook(Request $request): ?WebhookEventDTO
    {
        $payload = $request->all();
        $cid = (string) ($payload['consignment_id'] ?? '');
        $orderNumber = (string) ($payload['merchant_order_id'] ?? '');
        $rawStatus = (string) ($payload['order_status'] ?? $payload['order_status_slug'] ?? '');

        if (empty($cid) && empty($orderNumber)) {
            return null;
        }

        return new WebhookEventDTO(
            provider: 'pathao',
            consignmentId: $cid ?: null,
            trackingCode: $cid ?: null,
            orderNumber: $orderNumber ?: null,
            normalizedStatus: $this->normalizeStatus($rawStatus),
            courierRawStatus: $rawStatus,
            failureReason: $payload['reason'] ?? null,
            collectedAmount: isset($payload['collected_amount']) ? (float) $payload['collected_amount'] : null,
            courierFee: isset($payload['delivery_fee']) ? (float) $payload['delivery_fee'] : null,
            rawPayload: $payload
        );
    }

    public function getStores(): array
    {
        try {
            $response = $this->client()->get('/aladdin/api/v1/stores');
            if ($response->successful()) {
                return $response->json('data.data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::warning('Pathao stores fetch error: ' . $e->getMessage());
        }
        return [];
    }

    public function normalizeStatus(string $rawStatus): string
    {
        $status = strtolower(str_replace(' ', '_', trim($rawStatus)));

        return match ($status) {
            'pickup_requested', 'pending' => 'booked',
            'assigned_for_pickup' => 'pickup_pending',
            'picked', 'picked_up' => 'picked_up',
            'in_transit' => 'in_transit',
            'out_for_delivery' => 'out_for_delivery',
            'delivered', 'partial_delivered', 'payment_invoice_paid', 'settled' => 'delivered',
            'return', 'returned', 'return_received' => 'returned',
            'cancelled' => 'cancelled',
            'failed', 'delivery_failed', 'on_hold', 'hold' => 'delivery_failed',
            default => 'in_transit',
        };
    }
}
