<?php

namespace App\DTOs\Payment;

class PaymentWebhookDTO
{
    public function __construct(
        public bool $handled,
        public ?string $orderNumber = null,
        public ?string $transactionId = null,
        public string $status = 'pending',
        public float $amount = 0.0,
        public ?string $message = null,
        public array $data = []
    ) {}
}
