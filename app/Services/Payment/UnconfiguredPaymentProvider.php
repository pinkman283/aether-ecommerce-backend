<?php

namespace App\Services\Payment;

use App\Contracts\PaymentProviderInterface;
use App\DTOs\Payment\PaymentRefundDTO;
use App\DTOs\Payment\PaymentResultDTO;
use App\DTOs\Payment\PaymentVerificationDTO;
use App\DTOs\Payment\PaymentWebhookDTO;
use App\Models\Order;
use Illuminate\Http\Request;

class UnconfiguredPaymentProvider implements PaymentProviderInterface
{
    public function __construct(protected string $providerName = 'online') {}

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    public function isConfigured(): bool
    {
        // Explicitly disabled / unconfigured until real gateway credentials are provided
        return false;
    }

    public function initiatePayment(Order $order, array $options = []): PaymentResultDTO
    {
        return PaymentResultDTO::failed('Online payment is currently unavailable.');
    }

    public function verifyPayment(Order $order, array $payload = []): PaymentVerificationDTO
    {
        return new PaymentVerificationDTO(
            success: false,
            status: 'failed',
            message: 'Online payment is currently unavailable.'
        );
    }

    public function handleWebhook(Request $request): PaymentWebhookDTO
    {
        return new PaymentWebhookDTO(
            handled: false,
            message: 'Online payment is currently unavailable.'
        );
    }

    public function refund(Order $order, float $amount, ?string $reason = null): PaymentRefundDTO
    {
        return new PaymentRefundDTO(
            success: false,
            message: 'Online payment is currently unavailable.'
        );
    }
}
