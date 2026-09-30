<?php

namespace App\Services\Payment;

use App\Contracts\PaymentProviderInterface;
use App\DTOs\Payment\PaymentRefundDTO;
use App\DTOs\Payment\PaymentResultDTO;
use App\DTOs\Payment\PaymentVerificationDTO;
use App\DTOs\Payment\PaymentWebhookDTO;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Http\Request;

class CashOnDeliveryPaymentProvider implements PaymentProviderInterface
{
    public function getProviderName(): string
    {
        return 'cash_on_delivery';
    }

    public function isConfigured(): bool
    {
        return (bool) config('payment.cod.enabled', true);
    }

    public function initiatePayment(Order $order, array $options = []): PaymentResultDTO
    {
        // COD payment starts strictly as pending collection
        $order->update([
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'payment_transaction_id' => null,
        ]);

        // Register initial collection record in order payments ledger if not already recorded
        if (!$order->payments()->where('payment_method', 'cash_on_delivery')->exists()) {
            $order->payments()->create([
                'payment_number' => OrderPayment::generatePaymentNumber(),
                'payment_method' => 'cash_on_delivery',
                'provider' => 'manual',
                'amount' => $order->total_amount,
                'currency' => 'BDT',
                'status' => 'pending',
                'type' => 'collection',
                'notes' => 'Awaiting cash on delivery collection upon order arrival.',
                'created_by_user_id' => $order->user_id,
            ]);
        }

        return PaymentResultDTO::success(
            status: 'pending',
            transactionId: null,
            message: 'Cash on delivery payment registered. Amount due upon delivery.'
        );
    }

    public function verifyPayment(Order $order, array $payload = []): PaymentVerificationDTO
    {
        // COD verification reflects whether cash was collected
        $isCollected = ($order->payment_status === 'paid');

        return new PaymentVerificationDTO(
            success: $isCollected,
            status: $order->payment_status,
            amount: $order->total_amount,
            message: $isCollected ? 'Cash payment collected.' : 'Awaiting delivery collection.'
        );
    }

    public function handleWebhook(Request $request): PaymentWebhookDTO
    {
        // COD has no external gateway webhook
        return new PaymentWebhookDTO(
            handled: false,
            message: 'Cash on delivery does not process external payment gateway webhooks.'
        );
    }

    public function refund(Order $order, float $amount, ?string $reason = null): PaymentRefundDTO
    {
        $refundPayment = $order->payments()->create([
            'payment_number' => OrderPayment::generatePaymentNumber(),
            'payment_method' => 'cash_on_delivery',
            'provider' => 'manual',
            'amount' => -$amount,
            'currency' => 'BDT',
            'status' => 'completed',
            'type' => 'refund',
            'collected_at' => now(),
            'notes' => $reason ?: 'Cash on delivery refund',
        ]);

        $order->recalculatePaymentStatus();

        return new PaymentRefundDTO(
            success: true,
            refundId: $refundPayment->payment_number,
            amount: $amount,
            message: 'COD cash refund recorded.'
        );
    }
}
