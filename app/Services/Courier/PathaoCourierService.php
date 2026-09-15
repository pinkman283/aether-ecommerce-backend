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
    protected ?string $lastTokenError = null;

    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        $client = Http::baseUrl($this->baseUrl);
        if (app()->environment('local', 'testing') || $this->isTestMode || empty(ini_get('curl.cainfo'))) {
            $client = $client->withoutVerifying();
        }
        return $client;
    }

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

    protected function getCredentialHash(): string
    {
        return md5(($this->clientId ?? '') . ($this->username ?? '') . ($this->isTestMode ? '_test' : '_prod'));
    }

    /**
     * Retrieve OAuth2 Bearer Token with dynamic caching, refresh-token fallback,
     * safety expiration buffer, and concurrency locking.
     */
    public function getAccessToken(bool $forceRefresh = false): ?string
    {
        if (empty($this->clientId) || empty($this->clientSecret) || empty($this->username) || empty($this->password)) {
            return null;
        }

        $hash = $this->getCredentialHash();
        $tokenKey = 'pathao_courier_token_' . $hash;
        $refreshKey = 'pathao_courier_refresh_token_' . $hash;

        if (!$forceRefresh && Cache::has($tokenKey)) {
            $cached = Cache::get($tokenKey);
            if (!empty($cached)) {
                return $cached;
            }
        }

        // Concurrency lock to prevent redundant token requests during parallel operations
        $lockKey = 'pathao_token_lock_' . $hash;
        $lock = Cache::lock($lockKey, 10);

        return $lock->block(6, function () use ($tokenKey, $refreshKey, $forceRefresh) {
            if (!$forceRefresh && Cache::has($tokenKey)) {
                $cached = Cache::get($tokenKey);
                if (!empty($cached)) {
                    return $cached;
                }
            }

            // 1. Try Refresh Token grant if available and not explicitly forcing password refresh
            if (!$forceRefresh && Cache::has($refreshKey)) {
                $refreshToken = Cache::get($refreshKey);
                if (!empty($refreshToken)) {
                    $refreshed = $this->issueTokenWithRefreshToken($refreshToken);
                    if ($refreshed) {
                        return $refreshed;
                    }
                }
            }

            // 2. Fallback to standard Password grant
            return $this->issueTokenWithPasswordGrant();
        });
    }

    /**
     * Exchange refresh token for fresh access token
     */
    protected function issueTokenWithRefreshToken(string $refreshToken): ?string
    {
        try {
            $response = $this->http()
                ->timeout(15)
                ->post('/aladdin/api/v1/issue-token', [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                ]);

            if ($response->successful() && $response->json('access_token')) {
                return $this->storeTokenResponse($response->json());
            }

            Log::warning('Pathao refresh_token exchange returned non-success, will retry with password grant', [
                'status' => $response->status(),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::warning('Pathao refresh_token exception, falling back to password grant: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Issue fresh token using password grant
     */
    protected function issueTokenWithPasswordGrant(): ?string
    {
        try {
            $response = $this->http()
                ->timeout(15)
                ->post('/aladdin/api/v1/issue-token', [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'username' => $this->username,
                    'password' => $this->password,
                    'grant_type' => 'password',
                ]);

            if ($response->successful() && $response->json('access_token')) {
                $this->lastTokenError = null;
                return $this->storeTokenResponse($response->json());
            }

            $body = $response->json();
            $msg = $body['message'] ?? $body['error_description'] ?? $body['error'] ?? null;
            if (!$msg && !empty($body) && is_array($body)) {
                $msg = json_encode($body);
            }
            $this->lastTokenError = $msg ?: ('HTTP ' . $response->status() . ' - ' . substr($response->body(), 0, 150));

            Log::error('Pathao password grant token acquisition failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        } catch (\Throwable $e) {
            $this->lastTokenError = $e->getMessage();
            Log::error('Pathao token acquisition exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Store access token and optional refresh token with safety buffer
     */
    protected function storeTokenResponse(array $data): string
    {
        $accessToken = (string) $data['access_token'];
        $refreshToken = isset($data['refresh_token']) ? (string) $data['refresh_token'] : null;
        $expiresIn = (int) ($data['expires_in'] ?? 86400);

        // Safety margin: expire cache 5 minutes (300 seconds) before actual Pathao expiry
        $ttlSeconds = max(60, $expiresIn - 300);

        $hash = $this->getCredentialHash();
        $tokenKey = 'pathao_courier_token_' . $hash;
        $refreshKey = 'pathao_courier_refresh_token_' . $hash;

        Cache::put($tokenKey, $accessToken, now()->addSeconds($ttlSeconds));

        if (!empty($refreshToken)) {
            Cache::put($refreshKey, $refreshToken, now()->addDays(30));
        }

        return $accessToken;
    }

    /**
     * Invalidate cached tokens
     */
    public function clearTokenCache(): void
    {
        $hash = $this->getCredentialHash();
        Cache::forget('pathao_courier_token_' . $hash);
        Cache::forget('pathao_courier_refresh_token_' . $hash);
    }

    /**
     * Invalidate cached pickup stores
     */
    public function clearStoresCache(): void
    {
        $storeHash = md5(($this->clientId ?? '') . ($this->isTestMode ? '_test' : '_prod'));
        Cache::forget('pathao_stores_' . $storeHash);
    }

    /**
     * Execute HTTP call with Bearer token and automatic single-retry on 401 Unauthorized
     */
    public function sendRequest(string $method, string $endpoint, array $data = []): \Illuminate\Http\Client\Response
    {
        $token = $this->getAccessToken();

        $makeCall = function (?string $activeToken) use ($method, $endpoint, $data) {
            $client = $this->http()
                ->timeout(20)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $activeToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ]);

            return match (strtoupper($method)) {
                'GET' => $client->get($endpoint, $data),
                'POST' => $client->post($endpoint, $data),
                'PUT' => $client->put($endpoint, $data),
                'DELETE' => $client->delete($endpoint, $data),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };
        };

        $response = $makeCall($token);

        // Automatic recovery: If 401 Unauthorized, invalidate token cache and retry ONCE
        if ($response->status() === 401) {
            Log::warning("Pathao received 401 Unauthorized for {$endpoint}. Forcing token renewal and retrying once...");
            $this->clearTokenCache();
            $freshToken = $this->getAccessToken(true);
            if ($freshToken) {
                $response = $makeCall($freshToken);
            }
        }

        return $response;
    }

    protected function client()
    {
        $token = $this->getAccessToken();

        return $this->http()
            ->timeout(20)
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
            // Force a fresh token and store fetch for testing credentials live
            $this->clearTokenCache();
            $this->clearStoresCache();

            $token = $this->getAccessToken(true);
            if (!$token) {
                $detail = $this->lastTokenError ? ": {$this->lastTokenError}" : ". Check Client ID, Secret, Username, or Password.";
                return [
                    'success' => false,
                    'message' => 'Failed to obtain Pathao OAuth2 Token' . $detail,
                ];
            }

            // Test API access by retrieving stores
            $response = $this->sendRequest('GET', '/aladdin/api/v1/stores');
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

    /**
     * Retrieve Merchant Stores with 24-hour caching
     */
    public function getStores(): array
    {
        $storeHash = md5(($this->clientId ?? '') . ($this->isTestMode ? '_test' : '_prod'));
        $cacheKey = 'pathao_stores_' . $storeHash;

        return Cache::remember($cacheKey, now()->addHours(24), function () {
            try {
                $response = $this->sendRequest('GET', '/aladdin/api/v1/stores');
                if ($response->successful()) {
                    return $response->json('data.data') ?? [];
                }
                Log::warning('Pathao getStores failed: ' . $response->body());
            } catch (\Throwable $e) {
                Log::warning('Pathao stores fetch error: ' . $e->getMessage());
            }
            return [];
        });
    }

    public function getPickupStores(): array
    {
        return $this->getStores();
    }

    public function createShipment(ShipmentBookingDTO $dto): ShipmentBookingResultDTO
    {
        $cityId = $dto->recipientCityId;
        $zoneId = $dto->recipientZoneId;

        // If not directly in DTO, attempt fallback to order attributes
        if ((empty($cityId) || empty($zoneId)) && !empty($dto->orderId)) {
            $order = \App\Models\Order::find($dto->orderId);
            if ($order) {
                $cityId = $cityId ?: $order->shipping_city_id;
                $zoneId = $zoneId ?: $order->shipping_zone_id;
            }
        }

        // Reworked: Remove arbitrary "dhaka" text guessing. Require explicit City and Zone.
        if (empty($cityId) || empty($zoneId)) {
            return ShipmentBookingResultDTO::failed(
                'Pathao requires an explicitly selected recipient City and Zone. Please select City and Zone before booking.'
            );
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return ShipmentBookingResultDTO::failed('Pathao authentication failed. Please verify credentials.');
        }

        $storeId = $dto->pickupStoreId ?: $this->storeId;
        if (empty($storeId)) {
            $stores = $this->getStores();
            $storeId = $stores[0]['store_id'] ?? null;
        }

        if (empty($storeId)) {
            return ShipmentBookingResultDTO::failed('Pathao Store ID (Pickup Hub) is required. Please select or configure a pickup store.');
        }

        $payload = [
            'store_id' => (int) $storeId,
            'merchant_order_id' => $dto->invoiceNumber,
            'recipient_name' => $dto->recipientName,
            'recipient_phone' => $dto->recipientPhone,
            'recipient_address' => $dto->recipientAddress,
            'recipient_city' => (int) $cityId,
            'recipient_zone' => (int) $zoneId,
            'delivery_type' => 48, // Standard 48-hour delivery
            'item_type' => 1,      // Parcel
            'special_instruction' => $dto->notes ?: 'Handle with care',
            'item_quantity' => max(1, count($dto->items)),
            'item_weight' => round($dto->weight, 2),
            'amount_to_collect' => round($dto->codAmount, 2),
            'item_description' => 'Order ' . $dto->invoiceNumber,
        ];

        // 1. FIX PATHAO AREA MAPPING: Pass recipient_area when provided
        if (!empty($dto->recipientAreaId)) {
            $payload['recipient_area'] = (int) $dto->recipientAreaId;
        }

        try {
            $response = $this->sendRequest('POST', '/aladdin/api/v1/orders', $payload);
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

            $errMsg = $this->extractErrorMessage($response, $data, 'Failed to create Pathao order.');
            Log::error('Pathao booking failed', [
                'status' => $response->status(),
                'error' => $errMsg,
                'invoice' => $dto->invoiceNumber,
            ]);

            return ShipmentBookingResultDTO::failed($errMsg, $data ?? []);
        } catch (\Throwable $e) {
            Log::error('Pathao booking exception', ['error' => $e->getMessage(), 'invoice' => $dto->invoiceNumber]);
            return ShipmentBookingResultDTO::failed('Pathao API connection error: ' . $e->getMessage());
        }
    }

    public function trackShipment(string $consignmentIdOrTracking): ShipmentTrackingDTO
    {
        try {
            $response = $this->sendRequest('GET', "/aladdin/api/v1/orders/{$consignmentIdOrTracking}/info");
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

            $errMsg = $this->extractErrorMessage($response, $data, 'Unable to retrieve tracking info from Pathao.');
            return ShipmentTrackingDTO::failed($errMsg, $data ?? []);
        } catch (\Throwable $e) {
            return ShipmentTrackingDTO::failed('Pathao tracking failed: ' . $e->getMessage());
        }
    }

    /**
     * Calculate delivery price via Pathao Price Plan API
     */
    public function calculatePrice(array $params): array
    {
        $cityId = $params['recipient_city'] ?? $params['recipient_city_id'] ?? null;
        $zoneId = $params['recipient_zone'] ?? $params['recipient_zone_id'] ?? null;

        if (empty($cityId) || empty($zoneId)) {
            return [
                'success' => false,
                'message' => 'Recipient City and Zone are required to calculate Pathao delivery price.',
            ];
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return [
                'success' => false,
                'message' => 'Pathao authentication failed. Please verify credentials.',
            ];
        }

        $storeId = $params['store_id'] ?? $params['pickup_store_id'] ?? $this->storeId;
        if (empty($storeId)) {
            $stores = $this->getStores();
            $storeId = $stores[0]['store_id'] ?? null;
        }

        if (empty($storeId)) {
            return [
                'success' => false,
                'message' => 'Pathao pickup store is required for price calculation.',
            ];
        }

        $weight = (float) ($params['item_weight'] ?? $params['weight'] ?? 0.5);
        $deliveryType = (int) ($params['delivery_type'] ?? 48);
        $itemType = (int) ($params['item_type'] ?? 1);

        $payload = [
            'store_id' => (int) $storeId,
            'item_type' => $itemType,
            'delivery_type' => $deliveryType,
            'item_weight' => round(max(0.1, $weight), 2),
            'recipient_city' => (int) $cityId,
            'recipient_zone' => (int) $zoneId,
        ];

        try {
            $response = $this->sendRequest('POST', '/aladdin/api/v1/merchant/price-plan', $payload);
            $data = $response->json();

            if ($response->successful() && isset($data['data'])) {
                $planData = $data['data'];
                $price = (float) ($planData['price'] ?? 0.00);
                $additionalCharge = (float) ($planData['additional_charge'] ?? 0.00);
                $plan = (string) ($planData['plan'] ?? 'Standard Delivery');

                return [
                    'success' => true,
                    'price' => $price,
                    'delivery_charge' => $price,
                    'additional_charge' => $additionalCharge,
                    'cod_charge' => $additionalCharge,
                    'total_charge' => round($price + $additionalCharge, 2),
                    'plan' => $plan,
                    'raw_response' => $data,
                ];
            }

            $errMsg = $this->extractErrorMessage($response, $data, 'Unable to calculate Pathao delivery charge.');
            return [
                'success' => false,
                'message' => $errMsg,
            ];
        } catch (\Throwable $e) {
            Log::error('Pathao price calculation exception: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Pathao price calculation error: ' . $e->getMessage(),
            ];
        }
    }

    public function cancelShipment(string $consignmentId): bool
    {
        return true;
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $signature = $request->header('X-PATHAO-Signature') ?? $request->header('X-Pathao-Signature');
        $secret = $this->webhookSecret ?: $this->clientSecret;
        if (empty($signature) || empty($secret)) {
            return false;
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

    public function getCities(): array
    {
        return Cache::remember('pathao_cities', 86400 * 7, function () {
            try {
                $response = $this->sendRequest('GET', '/aladdin/api/v1/countries/1/city-list');
                if ($response->successful()) {
                    $cities = $response->json('data.data') ?? [];
                    if (!empty($cities)) {
                        return $cities;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Pathao getCities failed: ' . $e->getMessage());
            }
            return [
                ['city_id' => 1, 'city_name' => 'Dhaka'],
                ['city_id' => 2, 'city_name' => 'Chittagong'],
                ['city_id' => 3, 'city_name' => 'Sylhet'],
                ['city_id' => 4, 'city_name' => 'Rajshahi'],
                ['city_id' => 5, 'city_name' => 'Khulna'],
                ['city_id' => 6, 'city_name' => 'Barishal'],
                ['city_id' => 7, 'city_name' => 'Rangpur'],
                ['city_id' => 8, 'city_name' => 'Mymensingh'],
            ];
        });
    }

    public function getZones(int $cityId): array
    {
        return Cache::remember("pathao_zones_{$cityId}", 86400 * 7, function () use ($cityId) {
            try {
                $response = $this->sendRequest('GET', "/aladdin/api/v1/cities/{$cityId}/zone-list");
                if ($response->successful()) {
                    $zones = $response->json('data.data') ?? [];
                    if (!empty($zones)) {
                        return $zones;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Pathao getZones for city {$cityId} failed: " . $e->getMessage());
            }
            return [];
        });
    }

    public function getAreas(int $zoneId): array
    {
        return Cache::remember("pathao_areas_{$zoneId}", 86400 * 7, function () use ($zoneId) {
            try {
                $response = $this->sendRequest('GET', "/aladdin/api/v1/zones/{$zoneId}/area-list");
                if ($response->successful()) {
                    return $response->json('data.data') ?? [];
                }
            } catch (\Throwable $e) {
                Log::warning("Pathao getAreas for zone {$zoneId} failed: " . $e->getMessage());
            }
            return [];
        });
    }

    /**
     * Structured error message extraction without exposing credentials
     */
    protected function extractErrorMessage($response, ?array $data, string $default): string
    {
        if (!empty($data['message']) && is_string($data['message'])) {
            $msg = $data['message'];
            if (!empty($data['errors'])) {
                if (is_array($data['errors'])) {
                    $details = [];
                    foreach ($data['errors'] as $field => $errs) {
                        $errText = is_array($errs) ? implode(', ', $errs) : (string) $errs;
                        $details[] = is_string($field) ? "{$field}: {$errText}" : $errText;
                    }
                    $msg .= ' (' . implode('; ', $details) . ')';
                } elseif (is_string($data['errors'])) {
                    $msg .= ' (' . $data['errors'] . ')';
                }
            }
            return $msg;
        }

        if (!empty($data['errors'])) {
            if (is_array($data['errors'])) {
                $details = [];
                foreach ($data['errors'] as $field => $errs) {
                    $errText = is_array($errs) ? implode(', ', $errs) : (string) $errs;
                    $details[] = is_string($field) ? "{$field}: {$errText}" : $errText;
                }
                return implode('; ', $details);
            }
            return (string) $data['errors'];
        }

        $status = $response?->status();
        return match ($status) {
            400 => 'Bad request. Please verify order details with Pathao specifications.',
            401 => 'Pathao authentication failed. Check credentials in integration settings.',
            403 => 'Access forbidden by Pathao API.',
            404 => 'Pathao endpoint not found.',
            422 => 'Validation error returned by Pathao.',
            429 => 'Pathao rate limit exceeded. Please wait a moment and try again.',
            500, 502, 503, 504 => 'Pathao gateway error (' . $status . '). Please try again later.',
            default => $default . ($status ? " (HTTP {$status})" : ''),
        };
    }
}
