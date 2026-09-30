<?php

namespace App\DTOs\Payment;

class PaymentResultDTO
{
    public function __construct(
        public bool $success,
        public string $status, // 'pending', 'paid', 'failed'
        public ?string $transactionId = null,
        public ?string $redirectUrl = null,
        public ?string $message = null,
        public array $data = []
    ) {}

    public static function success(
        string $status = 'pending',
        ?string $transactionId = null,
        ?string $redirectUrl = null,
        ?string $message = null,
        array $data = []
    ): self {
        return new self(
            success: true,
            status: $status,
            transactionId: $transactionId,
            redirectUrl: $redirectUrl,
            message: $message,
            data: $data
        );
    }

    public static function failed(
        string $message,
        string $status = 'failed',
        array $data = []
    ): self {
        return new self(
            success: false,
            status: $status,
            transactionId: null,
            redirectUrl: null,
            message: $message,
            data: $data
        );
    }
}
