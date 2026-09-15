<?php

namespace App\Services\Courier;

use App\Contracts\CourierProviderInterface;
use App\DTOs\Courier\ShipmentBookingDTO;
use App\DTOs\Courier\ShipmentBookingResultDTO;
use App\DTOs\Courier\ShipmentTrackingDTO;
use App\DTOs\Courier\WebhookEventDTO;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RedxCourierService implements CourierProviderInterface
{
    protected string $baseUrl;
    protected ?string $apiToken;
    protected ?string $pickupStoreId;
    protected bool $isTestMode;

    public function __construct(?Integration $integration = null)
    {
        $integration = $integration ?? Integration::where('provider', 'redx')->first();
        $creds = $integration?->credentials ?? [];
        $settings = $integration?->settings ?? [];

        $this->isTestMode = (bool) ($integration?->is_test_mode ?? false);
        $this->baseUrl = $this->isTestMode
            ? 'https://sandbox.redx.com.bd/v1.0.0-beta'
            : 'https://openapi.redx.com.bd/v1.0.0-beta';

        $this->apiToken = $creds['api_token'] ?? config('services.redx.api_token');
        $this->pickupStoreId = $settings['pickup_store_id'] ?? null;
    }

    public function getProviderName(): string
    {
        return 'redx';
    }

    protected function client()
    {
        $client = Http::baseUrl($this->baseUrl);
        if (app()->environment('local', 'testing') || $this->isTestMode || empty(ini_get('curl.cainfo'))) {
            $client = $client->withoutVerifying();
        }
        return $client
            ->timeout(15)
            ->withHeaders([
                'API-ACCESS-TOKEN' => 'Bearer ' . $this->apiToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]);
    }

    public function testConnection(): array
    {
        if (empty($this->apiToken)) {
            return [
                'success' => false,
                'message' => 'RedX API Access Token is required.',
            ];
        }

        try {
            $response = $this->client()->get('/pickup_stores');
            if ($response->successful()) {
                $stores = $response->json('pickup_stores') ?? [];
                return [
                    'success' => true,
                    'message' => 'RedX Delivery Network connected successfully.',
                    'stores' => $stores,
                ];
            }

            return [
                'success' => false,
                'message' => 'RedX connection failed: ' . ($response->json('message') ?? 'HTTP ' . $response->status()),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'RedX gateway unreachable: ' . $e->getMessage(),
            ];
        }
    }

    public function createShipment(ShipmentBookingDTO $dto): ShipmentBookingResultDTO
    {
        if (empty($this->apiToken)) {
            return ShipmentBookingResultDTO::failed('RedX API token is missing or integration disabled.');
        }

        $payload = [
            'customer_name' => $dto->recipientName,
            'customer_phone' => $dto->recipientPhone,
            'delivery_area' => $dto->deliveryArea ?: 'Dhaka',
            'customer_address' => $dto->recipientAddress,
            'merchant_invoice_id' => $dto->invoiceNumber,
            'cash_collection_amount' => round($dto->codAmount, 2),
            'parcel_weight' => max(100, (int) ($dto->weight * 1000)), // grams
            'value' => round($dto->codAmount ?: 1000, 2),
            'instruction' => $dto->notes ?: 'Fragile, handle carefully',
        ];

        try {
            $response = $this->client()->post('/parcels', $payload);
            $data = $response->json();

            if ($response->successful() && !empty($data['tracking_id'])) {
                $trackingId = (string) $data['tracking_id'];
                $trackingUrl = 'https://redx.com.bd/track/' . $trackingId;

                return ShipmentBookingResultDTO::successful(
                    consignmentId: $trackingId,
                    trackingCode: $trackingId,
                    trackingUrl: $trackingUrl,
                    courierStatus: 'booked',
                    courierCharge: 0.00,
                    message: 'Consignment booked with RedX.',
                    rawResponse: $data
                );
            }

            $errMsg = $data['message'] ?? ($data['errors'] ? json_encode($data['errors']) : 'Failed to book RedX consignment.');
            Log::error('RedX booking failed', ['payload' => $payload, 'response' => $data]);
            return ShipmentBookingResultDTO::failed($errMsg, $data ?? []);
        } catch (\Throwable $e) {
            Log::error('RedX booking exception: ' . $e->getMessage());
            return ShipmentBookingResultDTO::failed('RedX API error: ' . $e->getMessage());
        }
    }

    public function trackShipment(string $consignmentIdOrTracking): ShipmentTrackingDTO
    {
        try {
            $response = $this->client()->get("/parcels/info/{$consignmentIdOrTracking}");
            $data = $response->json();

            if ($response->successful() && isset($data['parcel'])) {
                $parcel = $data['parcel'];
                $rawStatus = (string) ($parcel['status'] ?? 'in_transit');
                $trackingUrl = 'https://redx.com.bd/track/' . $consignmentIdOrTracking;

                return ShipmentTrackingDTO::successful(
                    normalizedStatus: $this->normalizeStatus($rawStatus),
                    courierRawStatus: $rawStatus,
                    consignmentId: $consignmentIdOrTracking,
                    trackingCode: $consignmentIdOrTracking,
                    trackingUrl: $trackingUrl,
                    lastUpdated: now()->toIso8601String(),
                    rawResponse: $data
                );
            }

            return ShipmentTrackingDTO::failed($data['message'] ?? 'RedX tracking info unavailable.', $data ?? []);
        } catch (\Throwable $e) {
            return ShipmentTrackingDTO::failed('RedX tracking error: ' . $e->getMessage());
        }
    }

    public function cancelShipment(string $consignmentId): bool
    {
        return true;
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $token = $request->header('API-ACCESS-TOKEN')
            ?? $request->header('X-RedX-Token')
            ?? $request->header('X-RedX-Signature')
            ?? $request->header('Authorization');

        $cleanToken = str_replace('Bearer ', '', $token ?? '');
        $expected = $this->apiToken;

        if (empty($expected) || empty($cleanToken)) {
            return false;
        }

        return hash_equals($expected, $cleanToken);
    }

    public function parseWebhook(Request $request): ?WebhookEventDTO
    {
        $payload = $request->all();
        $trackingId = (string) ($payload['tracking_id'] ?? '');
        $invoice = (string) ($payload['merchant_invoice_id'] ?? '');
        $rawStatus = (string) ($payload['status'] ?? '');

        if (empty($trackingId) && empty($invoice)) {
            return null;
        }

        return new WebhookEventDTO(
            provider: 'redx',
            consignmentId: $trackingId ?: null,
            trackingCode: $trackingId ?: null,
            orderNumber: $invoice ?: null,
            normalizedStatus: $this->normalizeStatus($rawStatus),
            courierRawStatus: $rawStatus,
            failureReason: $payload['reason'] ?? null,
            rawPayload: $payload
        );
    }

    public function getStores(): array
    {
        return [];
    }

    public function normalizeStatus(string $rawStatus): string
    {
        $status = strtolower(str_replace(' ', '_', trim($rawStatus)));

        return match ($status) {
            'ready_for_pickup', 'pickup_pending', 'booked' => 'booked',
            'picked_up' => 'picked_up',
            'in_transit' => 'in_transit',
            'out_for_delivery' => 'out_for_delivery',
            'delivered', 'completed' => 'delivered',
            'returned', 'rto' => 'returned',
            'cancelled' => 'cancelled',
            'failed', 'hold' => 'delivery_failed',
            default => 'in_transit',
        };
    }
}
