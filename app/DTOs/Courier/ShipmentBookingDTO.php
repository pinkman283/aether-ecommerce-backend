<?php

namespace App\DTOs\Courier;

class ShipmentBookingDTO
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $invoiceNumber,
        public readonly string $recipientName,
        public readonly string $recipientPhone,
        public readonly string $recipientAddress,
        public readonly float $codAmount,
        public readonly float $weight = 0.5,
        public readonly ?string $deliveryArea = null,
        public readonly ?string $pickupStoreId = null,
        public readonly ?string $notes = null,
        public readonly ?int $recipientCityId = null,
        public readonly ?int $recipientZoneId = null,
        public readonly ?int $recipientAreaId = null,
        public readonly array $items = [],
        public readonly array $metadata = []
    ) {}

    public static function fromOrder(\App\Models\Order $order, array $overrides = []): self
    {
        $address = $order->shipping_address ?? [];
        $recipientAddress = is_array($address)
            ? implode(', ', array_filter([
                $address['address_line1'] ?? '',
                $address['city'] ?? '',
                $address['state'] ?? '',
                $address['postal_code'] ?? '',
                $address['country'] ?? ''
            ]))
            : (string) $address;

        $recipientPhone = $overrides['recipient_phone']
            ?? $order->customer_phone
            ?? ($address['phone'] ?? '');

        // Standardize phone number for Bangladesh (e.g. 017XXXXXXXX)
        $cleanPhone = preg_replace('/[^0-9]/', '', $recipientPhone);
        if (str_starts_with($cleanPhone, '880')) {
            $cleanPhone = substr($cleanPhone, 2);
        }

        // If order is paid, COD amount to collect is 0; otherwise total_amount
        $codAmount = $order->payment_status === 'paid' ? 0.00 : (float) $order->total_amount;
        if (isset($overrides['cod_amount'])) {
            $codAmount = (float) $overrides['cod_amount'];
        }

        return new self(
            orderId: $order->id,
            invoiceNumber: $order->order_number,
            recipientName: $overrides['recipient_name'] ?? $order->customer_name,
            recipientPhone: $cleanPhone,
            recipientAddress: $overrides['recipient_address'] ?? $recipientAddress,
            codAmount: $codAmount,
            weight: (float) ($overrides['weight'] ?? 0.5),
            deliveryArea: $overrides['delivery_area'] ?? ($address['city'] ?? null),
            pickupStoreId: $overrides['pickup_store_id'] ?? null,
            notes: $overrides['notes'] ?? $order->notes,
            recipientCityId: isset($overrides['recipient_city_id']) ? (int) $overrides['recipient_city_id'] : ($order->shipping_city_id ?: null),
            recipientZoneId: isset($overrides['recipient_zone_id']) ? (int) $overrides['recipient_zone_id'] : ($order->shipping_zone_id ?: null),
            recipientAreaId: isset($overrides['recipient_area_id']) ? (int) $overrides['recipient_area_id'] : ($order->shipping_area_id ?: null),
            items: $order->items ? $order->items->toArray() : [],
            metadata: $overrides['metadata'] ?? []
        );
    }
}
