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

class SteadfastCourierService implements CourierProviderInterface
{
    protected string $baseUrl = 'https://portal.steadfast.com.bd/api/v1';
    protected ?string $apiKey;
    protected ?string $secretKey;
    protected bool $isTestMode = false;

    public function __construct(Integration|array|null $integration = null)
    {
        if (is_array($integration)) {
            $creds = $integration;
            $this->apiKey = $creds['api_key'] ?? null;
            $this->secretKey = $creds['secret_key'] ?? null;
            if (!empty($creds['base_url'])) {
                $this->baseUrl = $creds['base_url'];
            }
            $this->isTestMode = (bool) ($creds['is_test_mode'] ?? false);
            return;
        }

        $integration = $integration ?? Integration::where('provider', 'steadfast')->first();
        $creds = $integration?->credentials ?? [];

        $this->apiKey = $creds['api_key'] ?? config('services.steadfast.api_key');
        $this->secretKey = $creds['secret_key'] ?? config('services.steadfast.secret_key');
        $this->isTestMode = (bool) ($integration?->is_test_mode ?? false);
    }

    public function getProviderName(): string
    {
        return 'steadfast';
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
                'Api-Key' => $this->apiKey,
                'Secret-Key' => $this->secretKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]);
    }

    public function testConnection(): array
    {
        if (empty($this->apiKey) || empty($this->secretKey)) {
            return [
                'success' => false,
                'message' => 'Steadfast API Key and Secret Key are required.',
            ];
        }

        try {
            $response = $this->client()->get('/get_balance');

            if ($response->successful()) {
                $data = $response->json();
                $balance = $data['current_balance'] ?? 0.00;
                return [
                    'success' => true,
                    'message' => "Steadfast connected successfully. Gateway Balance: ৳" . number_format((float)$balance, 2),
                    'balance' => (float)$balance,
                    'latency_ms' => round($response->handlerStats()['total_time_us'] ?? 0 / 1000),
                ];
            }

            return [
                'success' => false,
                'message' => 'Steadfast connection failed: ' . ($response->json('message') ?? 'HTTP ' . $response->status()),
            ];
        } catch (\Throwable $e) {
            Log::warning('Steadfast test connection error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Steadfast gateway unreachable: ' . $e->getMessage(),
            ];
        }
    }

    public function createShipment(ShipmentBookingDTO $dto): ShipmentBookingResultDTO
    {
        if (empty($this->apiKey) || empty($this->secretKey)) {
            return ShipmentBookingResultDTO::failed('Steadfast credentials missing or disabled.');
        }

        $payload = [
            'invoice' => $dto->invoiceNumber,
            'recipient_name' => $dto->recipientName,
            'recipient_phone' => $dto->recipientPhone,
            'recipient_address' => $dto->recipientAddress,
            'cod_amount' => round($dto->codAmount, 2),
            'note' => $dto->notes ?: 'Order ' . $dto->invoiceNumber,
        ];

        try {
            $response = $this->client()->post('/create_order', $payload);
            $data = $response->json();

            if ($response->successful() && ($data['status'] ?? 0) === 200 && isset($data['consignment'])) {
                $c = $data['consignment'];
                $cid = (string) ($c['consignment_id'] ?? '');
                $trackingCode = (string) ($c['tracking_code'] ?? $cid);
                $trackingUrl = 'https://steadfast.com.bd/t/' . ($trackingCode ?: $cid);

                return ShipmentBookingResultDTO::successful(
                    consignmentId: $cid,
                    trackingCode: $trackingCode,
                    trackingUrl: $trackingUrl,
                    courierStatus: $this->normalizeStatus($c['status'] ?? 'in_review'),
                    courierCharge: 0.00,
                    message: $data['message'] ?? 'Consignment booked with Steadfast.',
                    rawResponse: $data
                );
            }

            $errMsg = $data['message'] ?? ($data['errors'] ? json_encode($data['errors']) : 'Failed to book Steadfast consignment (HTTP ' . $response->status() . ')');
            Log::error('Steadfast booking failed', ['payload' => $payload, 'response' => $data]);
            return ShipmentBookingResultDTO::failed($errMsg, $data ?? []);
        } catch (\Throwable $e) {
            Log::error('Steadfast booking exception', ['error' => $e->getMessage(), 'invoice' => $dto->invoiceNumber]);
            return ShipmentBookingResultDTO::failed('Steadfast API error: ' . $e->getMessage());
        }
    }

    public function trackShipment(string $consignmentIdOrTracking): ShipmentTrackingDTO
    {
        if (empty($this->apiKey) || empty($this->secretKey)) {
            return ShipmentTrackingDTO::failed('Steadfast credentials missing.');
        }

        try {
            // Attempt query by consignment_id or tracking_code
            $endpoint = is_numeric($consignmentIdOrTracking)
                ? "/status_by_cid/{$consignmentIdOrTracking}"
                : "/status_by_trackingcode/{$consignmentIdOrTracking}";

            $response = $this->client()->get($endpoint);
            $data = $response->json();

            if ($response->successful() && isset($data['delivery_status'])) {
                $rawStatus = (string) $data['delivery_status'];
                $normalized = $this->normalizeStatus($rawStatus);
                $trackingUrl = 'https://steadfast.com.bd/t/' . $consignmentIdOrTracking;

                return ShipmentTrackingDTO::successful(
                    normalizedStatus: $normalized,
                    courierRawStatus: $rawStatus,
                    consignmentId: is_numeric($consignmentIdOrTracking) ? $consignmentIdOrTracking : null,
                    trackingCode: $consignmentIdOrTracking,
                    trackingUrl: $trackingUrl,
                    lastUpdated: now()->toIso8601String(),
                    rawResponse: $data
                );
            }

            return ShipmentTrackingDTO::failed($data['message'] ?? 'Unable to retrieve tracking info from Steadfast.', $data ?? []);
        } catch (\Throwable $e) {
            return ShipmentTrackingDTO::failed('Steadfast tracking request failed: ' . $e->getMessage());
        }
    }

    public function cancelShipment(string $consignmentId): bool
    {
        // Steadfast currently does not offer an official public self-serve cancel API endpoint for automated booking,
        // but orders in 'in_review' can be marked cancelled internally.
        return true;
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        // Steadfast webhook authentication: check secret token in headers or query
        $token = $request->header('X-Steadfast-Token') 
            ?? $request->header('Secret-Key')
            ?? $request->query('token');

        if (empty($this->secretKey) || empty($token)) {
            return false; // Strictly reject if secret key or token is missing
        }

        return hash_equals($this->secretKey, $token);
    }

    public function parseWebhook(Request $request): ?WebhookEventDTO
    {
        $payload = $request->all();
        $cid = (string) ($payload['consignment_id'] ?? $payload['cid'] ?? '');
        $trackingCode = (string) ($payload['tracking_code'] ?? '');
        $invoice = (string) ($payload['invoice'] ?? $payload['order_id'] ?? '');
        $rawStatus = (string) ($payload['status'] ?? $payload['delivery_status'] ?? '');

        if (empty($cid) && empty($trackingCode) && empty($invoice)) {
            return null;
        }

        return new WebhookEventDTO(
            provider: 'steadfast',
            consignmentId: $cid ?: null,
            trackingCode: $trackingCode ?: null,
            orderNumber: $invoice ?: null,
            normalizedStatus: $this->normalizeStatus($rawStatus),
            courierRawStatus: $rawStatus,
            failureReason: $payload['reason'] ?? null,
            collectedAmount: isset($payload['collected_amount']) ? (float) $payload['collected_amount'] : null,
            courierFee: isset($payload['delivery_charge']) ? (float) $payload['delivery_charge'] : null,
            rawPayload: $payload
        );
    }

    public function getStores(): array
    {
        return []; // Steadfast uses merchant primary pickup address
    }

    public function normalizeStatus(string $rawStatus): string
    {
        $status = strtolower(trim($rawStatus));

        return match ($status) {
            'in_review', 'pending' => 'booked',
            'in_transit', 'picked', 'transit' => 'in_transit',
            'out_for_delivery' => 'out_for_delivery',
            'delivered', 'partial_delivered' => 'delivered',
            'cancelled' => 'cancelled',
            'hold', 'failed', 'delivery_failed' => 'delivery_failed',
            'returned', 'return' => 'returned',
            default => 'in_transit',
        };
    }
}
