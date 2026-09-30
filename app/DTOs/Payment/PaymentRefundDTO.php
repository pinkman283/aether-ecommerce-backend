<?php

namespace App\DTOs\Payment;

class PaymentRefundDTO
{
    public function __construct(
        public bool $success,
        public ?string $refundId = null,
        public float $amount = 0.0,
        public ?string $message = null,
        public array $data = []
    ) {}
}
