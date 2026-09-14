<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderTimelineEvent;
use Illuminate\Support\Collection;

class OrderTimelineService
{
    /**
     * Record an authoritative milestone event on an order.
     */
    public static function recordEvent(
        Order|int $order,
        string $eventType,
        string $title,
        ?string $description = null,
        ?string $actorName = null,
        string $iconType = 'check',
        array $metadata = []
    ): OrderTimelineEvent {
        $orderId = $order instanceof Order ? $order->id : $order;

        return OrderTimelineEvent::create([
            'order_id' => $orderId,
            'event_type' => $eventType,
            'title' => $title,
            'description' => $description,
            'actor_name' => $actorName ?? (auth()->user()?->name ?? 'System'),
            'icon_type' => $iconType,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Retrieve the complete chronological timeline for an order.
     * Merges recorded timeline events with any shipment, return, and audit log milestones.
     */
    public static function getTimeline(Order $order): Collection
    {
        $order->loadMissing(['shipments', 'returns.items']);

        $recordedEvents = $order->timelineEvents()->orderBy('created_at', 'asc')->get();

        // If no explicit timeline events exist yet for this order (legacy/seed order), synthesize milestones
        if ($recordedEvents->isEmpty()) {
            self::seedLegacyMilestones($order);
            $recordedEvents = $order->timelineEvents()->orderBy('created_at', 'asc')->get();
        }

        return $recordedEvents;
    }

    /**
     * Synthesize timeline milestones for existing orders without recorded events.
     */
    protected static function seedLegacyMilestones(Order $order): void
    {
        // 1. Order Placed
        self::recordEvent(
            order: $order,
            eventType: 'order_placed',
            title: 'Order Placed',
            description: "Customer placed order for " . count($order->items ?? []) . " item(s). Total: ৳" . number_format($order->total_amount, 2),
            actorName: $order->customer_name ?: 'Customer',
            iconType: 'check',
            metadata: ['order_number' => $order->order_number]
        );

        // 2. Confirmed / Processing
        if (in_array($order->order_status, ['processing', 'shipped', 'delivered', 'returned', 'rto'])) {
            self::recordEvent(
                order: $order,
                eventType: 'confirmed',
                title: 'Order Confirmed',
                description: 'Payment verification complete. Order released to fulfillment center.',
                actorName: 'Operations Staff',
                iconType: 'check'
            );
        }

        // 3. Shipments
        foreach ($order->shipments as $shipment) {
            self::recordEvent(
                order: $order,
                eventType: 'courier_booked',
                title: "Courier Booked ({$shipment->provider})",
                description: "Consignment ID: {$shipment->consignment_id}, Tracking: {$shipment->tracking_code}",
                actorName: 'Logistics',
                iconType: 'truck',
                metadata: ['consignment_id' => $shipment->consignment_id, 'provider' => $shipment->provider]
            );

            if (in_array($shipment->status, ['in_transit', 'out_for_delivery', 'delivered', 'returned'])) {
                self::recordEvent(
                    order: $order,
                    eventType: 'picked_up',
                    title: 'Parcel Picked Up by Courier',
                    description: "Package received at {$shipment->provider} hub and in transit.",
                    actorName: ucfirst($shipment->provider),
                    iconType: 'truck'
                );
            }

            if (in_array($shipment->status, ['out_for_delivery', 'delivered'])) {
                self::recordEvent(
                    order: $order,
                    eventType: 'out_for_delivery',
                    title: 'Out for Delivery',
                    description: 'Rider assigned for delivery to customer shipping address.',
                    actorName: ucfirst($shipment->provider),
                    iconType: 'truck'
                );
            }

            if ($shipment->status === 'delivered') {
                self::recordEvent(
                    order: $order,
                    eventType: 'delivered',
                    title: 'Delivered to Customer',
                    description: 'Customer accepted delivery and COD settled.',
                    actorName: ucfirst($shipment->provider),
                    iconType: 'check'
                );
            }

            if (in_array($shipment->status, ['delivery_failed', 'returned', 'return_in_transit'])) {
                self::recordEvent(
                    order: $order,
                    eventType: 'customer_refused',
                    title: 'Customer Refused / Delivery Failed',
                    description: 'Customer could not be reached or refused parcel at doorstep.',
                    actorName: ucfirst($shipment->provider),
                    iconType: 'alert'
                );
                self::recordEvent(
                    order: $order,
                    eventType: 'rto_initiated',
                    title: 'RTO Initiated',
                    description: 'Courier initiated Return to Origin (RTO) to merchant warehouse.',
                    actorName: ucfirst($shipment->provider),
                    iconType: 'rotate'
                );
            }
        }

        // 4. Returns & QC
        foreach ($order->returns as $return) {
            self::recordEvent(
                order: $order,
                eventType: 'returned_to_warehouse',
                title: "Returned to Warehouse (Return #{$return->return_number})",
                description: "Parcel arrived at warehouse intake dock for inspection.",
                actorName: 'Intake Dock',
                iconType: 'rotate'
            );

            if (in_array($return->status, ['qc_completed', 'resolved'])) {
                self::recordEvent(
                    order: $order,
                    eventType: 'qc_completed',
                    title: 'Quality Control (QC) Completed',
                    description: "Inspected {$return->items->count()} item(s). Disposition finalized.",
                    actorName: 'QC Inspector',
                    iconType: 'check'
                );
                self::recordEvent(
                    order: $order,
                    eventType: 'restocked',
                    title: 'Inventory Restocked / Disposed',
                    description: 'Sellable units returned to stock, damaged units quarantined.',
                    actorName: 'Warehouse',
                    iconType: 'check'
                );
                self::recordEvent(
                    order: $order,
                    eventType: 'accounting_adjusted',
                    title: 'Accounting Adjusted',
                    description: 'Sales Revenue and AR adjusted on General Ledger.',
                    actorName: 'Accounting Engine',
                    iconType: 'dollar'
                );
            }
        }

        // 5. Cancelled
        if ($order->order_status === 'cancelled') {
            self::recordEvent(
                order: $order,
                eventType: 'cancelled',
                title: 'Order Cancelled',
                description: $order->notes ?: 'Order cancelled and inventory restored.',
                actorName: 'Admin',
                iconType: 'x'
            );
        }
    }
}
