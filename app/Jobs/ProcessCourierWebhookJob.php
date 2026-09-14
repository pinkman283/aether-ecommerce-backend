<?php

namespace App\Jobs;

use App\DTOs\Courier\WebhookEventDTO;
use App\Models\AuditLog;
use App\Models\CourierWebhookLog;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\OrderReturnService;
use App\Services\OrderTimelineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessCourierWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 15;

    public function __construct(
        public string $provider,
        public array $eventData,
        public int $webhookLogId
    ) {}

    public function handle(): void
    {
        $log = CourierWebhookLog::find($this->webhookLogId);
        $consignmentId = $this->eventData['consignmentId'] ?? null;
        $orderNumber = $this->eventData['orderNumber'] ?? null;
        $newStatus = $this->eventData['standardizedStatus'] ?? null;

        Log::info("Processing queued webhook event for provider [{$this->provider}] consignment [{$consignmentId}]");

        $shipment = Shipment::where('provider', $this->provider)
            ->where(function ($q) use ($consignmentId, $orderNumber) {
                if ($consignmentId) {
                    $q->where('consignment_id', $consignmentId)
                      ->orWhere('tracking_code', $consignmentId);
                }
                if ($orderNumber) {
                    $q->orWhereHas('order', fn($oq) => $oq->where('order_number', $orderNumber));
                }
            })
            ->latest('id')
            ->first();

        if (!$shipment) {
            if ($log) {
                $log->update([
                    'status' => 'ignored',
                    'error_message' => "Shipment not found for consignment [{$consignmentId}].",
                    'processed_at' => now(),
                ]);
            }
            return;
        }

        if ($log) {
            $log->update([
                'shipment_id' => $shipment->id,
                'order_id' => $shipment->order_id,
            ]);
        }

        DB::transaction(function () use ($shipment, $newStatus, $log) {
            $updates = [
                'courier_status_raw' => $this->eventData['courierRawStatus'] ?? $shipment->courier_status_raw,
                'last_synced_at' => now(),
            ];

            if ($newStatus && $shipment->status !== $newStatus) {
                $updates['status'] = $newStatus;
            }

            if (!empty($this->eventData['courierFee'])) {
                $updates['courier_charge'] = (float) $this->eventData['courierFee'];
            }

            $shipment->update($updates);

            $order = $shipment->order;
            if ($order) {
                if ($newStatus === 'delivered') {
                    $orderUpdates = ['order_status' => 'delivered', 'delivered_at' => now()];
                    if ($order->payment_status === 'pending') {
                        $orderUpdates['payment_status'] = 'paid';
                    }
                    $order->update($orderUpdates);
                } elseif (in_array($newStatus, ['returned', 'return_initiated'])) {
                    app(OrderReturnService::class)->handleCourierRtoEvent($shipment, [
                        'rto_charge' => (float) ($this->eventData['courierFee'] ?? 0.00),
                        'collected_amount' => (float) ($this->eventData['collectedAmount'] ?? 0.00),
                        'return_reason' => $this->eventData['failureReason'] ?? 'RTO webhook received',
                    ]);
                }
            }

            if ($log) {
                $log->update(['status' => 'processed', 'processed_at' => now()]);
            }
        });
    }
}
