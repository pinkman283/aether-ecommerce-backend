<?php

namespace App\Services\Courier;

use App\Contracts\CourierProviderInterface;
use App\DTOs\Courier\ShipmentBookingDTO;
use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\AccountingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class CourierManager
{
    protected array $drivers = [];

    /**
     * Resolve courier provider instance
     */
    public function driver(?string $provider = null): CourierProviderInterface
    {
        $provider = strtolower(trim($provider ?: $this->getDefaultProvider()));

        if (isset($this->drivers[$provider])) {
            return $this->drivers[$provider];
        }

        $integration = Integration::where('provider', $provider)->first();

        $instance = match ($provider) {
            'steadfast' => new SteadfastCourierService($integration),
            'pathao' => new PathaoCourierService($integration),
            'redx' => new RedxCourierService($integration),
            default => throw new InvalidArgumentException("Unsupported courier provider [{$provider}]."),
        };

        return $this->drivers[$provider] = $instance;
    }

    /**
     * Get default courier provider slug
     */
    public function getDefaultProvider(): string
    {
        $default = Integration::where('category', 'courier')
            ->where('is_enabled', true)
            ->orderBy('id')
            ->first();

        return $default?->provider ?: 'steadfast';
    }

    /**
     * Return all available courier providers configured in system
     */
    public function getAvailableProviders(): array
    {
        $integrations = Integration::where('category', 'courier')->get();

        return $integrations->map(function ($integration) {
            $stores = [];
            if ($integration->is_enabled && $integration->provider === 'pathao') {
                try {
                    $stores = $this->driver('pathao')->getStores();
                } catch (\Throwable $e) {
                    $stores = [];
                }
            }

            return [
                'provider' => $integration->provider,
                'name' => $integration->name,
                'is_enabled' => (bool) $integration->is_enabled,
                'is_test_mode' => (bool) $integration->is_test_mode,
                'test_status' => $integration->test_status,
                'test_message' => $integration->test_message,
                'stores' => $stores,
            ];
        })->toArray();
    }

    /**
     * Book order shipment with courier (Atomically & Idempotently)
     */
    public function bookShipment(Order $order, array $params = []): Shipment
    {
        $lockKey = "courier_book_order_{$order->id}";
        $lock = Cache::lock($lockKey, 20);

        if (!$lock->get()) {
            throw new \RuntimeException("A courier booking for Order #{$order->order_number} is already in progress. Please wait a moment.");
        }

        try {
            // 1. Prevent duplicate active booking
            $existingActive = $order->shipments()
                ->whereNotIn('status', ['cancelled', 'delivery_failed'])
                ->first();

            if ($existingActive) {
                throw new \RuntimeException("Order already has an active consignment (#{$existingActive->consignment_id}) with provider {$existingActive->provider}.");
            }

            $provider = $params['provider'] ?? $this->getDefaultProvider();
            $service = $this->driver($provider);

            // 2. Prepare standardized booking DTO
            $dto = ShipmentBookingDTO::fromOrder($order, $params);

            // 3. Dispatch external courier API request
            $result = $service->createShipment($dto);

            if (!$result->success) {
                throw new \RuntimeException($result->errorMessage ?: "Failed to book shipment with {$provider}.");
            }

            // 4. Persist shipment record in database
            return DB::transaction(function () use ($order, $provider, $dto, $result, $params) {
                $shipment = Shipment::create([
                    'order_id' => $order->id,
                    'provider' => $provider,
                    'consignment_id' => $result->consignmentId,
                    'tracking_code' => $result->trackingCode ?: $result->consignmentId,
                    'tracking_url' => $result->trackingUrl,
                    'status' => 'booked',
                    'courier_status_raw' => $result->courierStatus ?: 'booked',
                    'recipient_name' => $dto->recipientName,
                    'recipient_phone' => $dto->recipientPhone,
                    'recipient_address' => $dto->recipientAddress,
                    'cod_amount' => $dto->codAmount,
                    'courier_charge' => $result->courierCharge,
                    'weight' => $dto->weight,
                    'delivery_area' => $dto->deliveryArea,
                    'pickup_store_id' => $dto->pickupStoreId,
                    'notes' => $dto->notes,
                    'booked_at' => now(),
                    'last_synced_at' => now(),
                    'raw_response' => $result->rawResponse,
                    'metadata' => $params['metadata'] ?? null,
                ]);

                // 5. Update Order carrier & tracking for seamless backward compatibility
                $orderUpdates = [
                    'carrier' => ucfirst($provider),
                    'tracking_code' => $shipment->tracking_code,
                    'order_status' => 'shipped',
                    'shipped_at' => $order->shipped_at ?: now(),
                ];
                if (!empty($params['recipient_city_id'])) {
                    $orderUpdates['shipping_city_id'] = (int) $params['recipient_city_id'];
                }
                if (!empty($params['recipient_zone_id'])) {
                    $orderUpdates['shipping_zone_id'] = (int) $params['recipient_zone_id'];
                }
                if (!empty($params['recipient_area_id'])) {
                    $orderUpdates['shipping_area_id'] = (int) $params['recipient_area_id'];
                }
                $order->update($orderUpdates);

                // 6. Record in Order Lifecycle Timeline
                try {
                    \App\Services\OrderTimelineService::recordEvent(
                        order: $order,
                        eventType: 'courier_booked',
                        title: 'Courier Booked',
                        description: "Booked with " . ucfirst($shipment->provider) . " (Consignment: {$shipment->consignment_id}, Tracking: {$shipment->tracking_code})",
                        actorName: auth()->user()?->name ?? 'System Admin',
                        iconType: 'truck',
                        metadata: [
                            'actor_type' => auth()->check() ? 'admin' : 'system',
                            'actor_id' => auth()->id(),
                            'provider' => $shipment->provider,
                            'consignment_id' => $shipment->consignment_id,
                            'tracking_code' => $shipment->tracking_code,
                            'cod_amount' => $shipment->cod_amount,
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::warning('Failed to record courier_booked timeline event: ' . $e->getMessage());
                }

                // 7. Record Audit Log
                AuditLog::log(
                    auth()->user(),
                    'courier.booked',
                    'Shipment',
                    $shipment->id,
                    "Booked parcel with {$provider} for Order #{$order->order_number}. Consignment: {$shipment->consignment_id}",
                    null,
                    $shipment->toArray()
                );

                // 8. Post accounting entry if courier expense is known
                try {
                    AccountingService::postCourierBooking($shipment);
                } catch (\Throwable $e) {
                    Log::warning('Courier booking accounting entry skipped: ' . $e->getMessage());
                }

                // 9. Dispatch customer notification
                try {
                    \App\Services\CustomerNotificationService::sendShipmentDispatched($order, $shipment);
                } catch (\Throwable $e) {
                    Log::warning('Dispatch customer notification skipped: ' . $e->getMessage());
                }

                return $shipment;
            });
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Poll live status from courier and update database records
     */
    public function syncShipmentStatus(Shipment $shipment): Shipment
    {
        if (empty($shipment->consignment_id) && empty($shipment->tracking_code)) {
            return $shipment;
        }

        $service = $this->driver($shipment->provider);
        $identifier = $shipment->consignment_id ?: $shipment->tracking_code;
        $tracking = $service->trackShipment($identifier);

        if (!$tracking->success) {
            return $shipment;
        }

        $oldStatus = $shipment->status;
        $newStatus = $tracking->normalizedStatus;

        $updates = [
            'status' => $newStatus,
            'courier_status_raw' => $tracking->courierRawStatus ?: $shipment->courier_status_raw,
            'last_synced_at' => now(),
        ];

        if ($newStatus === 'in_transit' && !$shipment->in_transit_at) {
            $updates['in_transit_at'] = now();
        } elseif ($newStatus === 'out_for_delivery' && !$shipment->out_for_delivery_at) {
            $updates['out_for_delivery_at'] = now();
        } elseif ($newStatus === 'delivered' && !$shipment->delivered_at) {
            $updates['delivered_at'] = now();
        } elseif ($newStatus === 'returned' && !$shipment->returned_at) {
            $updates['returned_at'] = now();
        } elseif ($newStatus === 'cancelled' && !$shipment->cancelled_at) {
            $updates['cancelled_at'] = now();
        }

        $shipment->update($updates);

        // Reflect onto commercial Order status
        $order = $shipment->order;
        if ($order) {
            if ($newStatus === 'delivered') {
                $order->update([
                    'order_status' => 'delivered',
                    'delivered_at' => now(),
                    'payment_status' => $order->payment_method === 'cash_on_delivery' ? 'paid' : $order->payment_status,
                ]);
            } elseif ($newStatus === 'in_transit' && in_array($order->order_status, ['pending', 'confirmed', 'processing'])) {
                $order->update([
                    'order_status' => 'processing',
                    'shipped_at' => $order->shipped_at ?: now(),
                ]);
            }
        }

        return $shipment;
    }

    /**
     * Calculate delivery charge with courier provider if supported
     */
    public function calculateDeliveryPrice(string $provider, array $params): array
    {
        $service = $this->driver($provider);
        if (method_exists($service, 'calculatePrice')) {
            return $service->calculatePrice($params);
        }

        return [
            'success' => false,
            'message' => "Courier provider [{$provider}] does not support dynamic price calculation.",
        ];
    }
}
