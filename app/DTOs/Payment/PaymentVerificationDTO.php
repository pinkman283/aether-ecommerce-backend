<?php

namespace App\DTOs\Payment;

class PaymentVerificationDTO
{
    public function __construct(
        public bool $success,
        public string $status, // 'pending', 'paid', 'failed'
        public ?string $transactionId = null,
        public float $amount = 0.0,
        public ?string $message = null,
        public array $data = []
    ) {}
}
