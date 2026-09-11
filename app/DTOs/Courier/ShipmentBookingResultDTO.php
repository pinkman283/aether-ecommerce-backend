<?php

namespace App\DTOs\Courier;

class ShipmentBookingResultDTO
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $consignmentId = null,
        public readonly ?string $trackingCode = null,
        public readonly ?string $trackingUrl = null,
        public readonly ?string $courierStatus = null,
        public readonly float $courierCharge = 0.00,
        public readonly ?string $message = null,
        public readonly ?string $errorMessage = null,
        public readonly array $rawResponse = []
    ) {}

    public static function successful(
        string $consignmentId,
        ?string $trackingCode = null,
        ?string $trackingUrl = null,
        ?string $courierStatus = 'booked',
        float $courierCharge = 0.00,
        ?string $message = 'Consignment created successfully.',
        array $rawResponse = []
    ): self {
        return new self(
            success: true,
            consignmentId: $consignmentId,
            trackingCode: $trackingCode ?: $consignmentId,
            trackingUrl: $trackingUrl,
            courierStatus: $courierStatus,
            courierCharge: $courierCharge,
            message: $message,
            rawResponse: $rawResponse
        );
    }

    public static function failed(
        string $errorMessage,
        array $rawResponse = []
    ): self {
        return new self(
            success: false,
            errorMessage: $errorMessage,
            rawResponse: $rawResponse
        );
    }
}
