<?php

namespace App\DTOs\Courier;

class ShipmentTrackingDTO
{
    public function __construct(
        public readonly bool $success,
        public readonly string $normalizedStatus, // booked, in_transit, out_for_delivery, delivered, delivery_failed, returned, cancelled
        public readonly ?string $courierRawStatus = null,
        public readonly ?string $consignmentId = null,
        public readonly ?string $trackingCode = null,
        public readonly ?string $trackingUrl = null,
        public readonly ?string $lastUpdated = null,
        public readonly ?string $failureReason = null,
        public readonly array $events = [],
        public readonly array $rawResponse = []
    ) {}

    public static function successful(
        string $normalizedStatus,
        ?string $courierRawStatus = null,
        ?string $consignmentId = null,
        ?string $trackingCode = null,
        ?string $trackingUrl = null,
        ?string $lastUpdated = null,
        ?string $failureReason = null,
        array $events = [],
        array $rawResponse = []
    ): self {
        return new self(
            success: true,
            normalizedStatus: $normalizedStatus,
            courierRawStatus: $courierRawStatus,
            consignmentId: $consignmentId,
            trackingCode: $trackingCode,
            trackingUrl: $trackingUrl,
            lastUpdated: $lastUpdated ?: now()->toIso8601String(),
            failureReason: $failureReason,
            events: $events,
            rawResponse: $rawResponse
        );
    }

    public static function failed(string $errorMessage, array $rawResponse = []): self
    {
        return new self(
            success: false,
            normalizedStatus: 'unknown',
            failureReason: $errorMessage,
            rawResponse: $rawResponse
        );
    }
}
