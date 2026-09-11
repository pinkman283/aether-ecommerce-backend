<?php

namespace App\DTOs\Courier;

class WebhookEventDTO
{
    public function __construct(
        public readonly string $provider,
        public readonly ?string $consignmentId,
        public readonly ?string $trackingCode,
        public readonly ?string $orderNumber,
        public readonly string $normalizedStatus,
        public readonly ?string $courierRawStatus,
        public readonly ?string $failureReason = null,
        public readonly ?string $returnReason = null,
        public readonly ?float $collectedAmount = null,
        public readonly ?float $courierFee = null,
        public readonly array $rawPayload = []
    ) {}
}
