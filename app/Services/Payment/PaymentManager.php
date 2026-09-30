<?php

namespace App\Services\Payment;

use App\Contracts\PaymentProviderInterface;
use InvalidArgumentException;

class PaymentManager
{
    /**
     * Cached resolved provider instances
     *
     * @var array<string, PaymentProviderInterface>
     */
    protected array $providers = [];

    /**
     * Resolve payment provider instance
     */
    public function driver(?string $method = null): PaymentProviderInterface
    {
        $normalized = $this->normalizePaymentMethod($method ?: config('payment.default', 'cash_on_delivery'));

        if (isset($this->providers[$normalized])) {
            return $this->providers[$normalized];
        }

        $instance = match ($normalized) {
            'cash_on_delivery' => new CashOnDeliveryPaymentProvider(),
            'online', 'credit_card', 'card', 'sslcommerz', 'bkash', 'nagad', 'stripe' => new UnconfiguredPaymentProvider($normalized),
            default => throw new InvalidArgumentException("Unsupported payment method [{$method}]."),
        };

        return $this->providers[$normalized] = $instance;
    }

    /**
     * Normalize aliases to canonical values (e.g. 'cod' -> 'cash_on_delivery')
     */
    public function normalizePaymentMethod(?string $method): string
    {
        $cleaned = strtolower(trim((string) $method));

        if ($cleaned === 'cod' || $cleaned === 'cash_on_delivery') {
            return 'cash_on_delivery';
        }

        if (in_array($cleaned, ['card', 'credit_card', 'online', 'online_payment'])) {
            return 'online';
        }

        return $cleaned;
    }

    /**
     * Check if payment method identifier is known to the system
     */
    public function isKnownMethod(string $method): bool
    {
        $normalized = $this->normalizePaymentMethod($method);
        return in_array($normalized, [
            'cash_on_delivery',
            'online',
            'credit_card',
            'card',
            'sslcommerz',
            'bkash',
            'nagad',
            'stripe',
        ]);
    }

    /**
     * Return public storefront payment options without leaking any secrets
     *
     * @return array<int, array{id: string, name: string, description: string, is_available: bool}>
     */
    public function getPublicPaymentMethods(): array
    {
        $codProvider = $this->driver('cash_on_delivery');
        $isOnlineConfigured = (bool) config('payment.online.enabled', false) 
            && !empty(config('payment.online.provider'))
            && !empty(config('payment.online.api_key'));

        return [
            [
                'id' => 'cash_on_delivery',
                'name' => 'Cash On Delivery',
                'description' => 'Pay with cash when your parcel arrives at your doorstep.',
                'is_available' => $codProvider->isConfigured(),
            ],
            [
                'id' => 'online',
                'name' => 'Online Payment',
                'description' => $isOnlineConfigured 
                    ? 'Pay securely online.' 
                    : 'Online payment is currently unavailable. Coming soon.',
                'is_available' => $isOnlineConfigured,
            ],
        ];
    }
}
