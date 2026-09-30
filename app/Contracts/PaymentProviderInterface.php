<?php

namespace App\Contracts;

use App\DTOs\Payment\PaymentRefundDTO;
use App\DTOs\Payment\PaymentResultDTO;
use App\DTOs\Payment\PaymentVerificationDTO;
use App\DTOs\Payment\PaymentWebhookDTO;
use App\Models\Order;
use Illuminate\Http\Request;

interface PaymentProviderInterface
{
    /**
     * Provider slug: 'cash_on_delivery', 'online_gateway'
     */
    public function getProviderName(): string;

    /**
     * Check if payment provider is configured and available for checkout
     */
    public function isConfigured(): bool;

    /**
     * Initiate or register payment for an order
     */
    public function initiatePayment(Order $order, array $options = []): PaymentResultDTO;

    /**
     * Verify payment status with provider
     */
    public function verifyPayment(Order $order, array $payload = []): PaymentVerificationDTO;

    /**
     * Process inbound payment gateway webhook/callback
     */
    public function handleWebhook(Request $request): PaymentWebhookDTO;

    /**
     * Execute full or partial refund
     */
    public function refund(Order $order, float $amount, ?string $reason = null): PaymentRefundDTO;
}
