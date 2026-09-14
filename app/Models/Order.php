<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'order_number',
        'order_source',
        'pos_register_session_id',
        'cashier_user_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'shipping_address',
        'shipping_city_id',
        'shipping_zone_id',
        'shipping_area_id',
        'billing_address',
        'subtotal',
        'tax_amount',
        'shipping_amount',
        'discount_amount',
        'total_amount',
        'cogs_amount',
        'gross_profit',
        'cash_received',
        'change_returned',
        'payment_status',
        'payment_method',
        'payment_transaction_id',
        'order_status',
        'return_status',
        'amount_collected_courier',
        'amount_remitted_merchant',
        'amount_refunded',
        'shipping_method',
        'tracking_code',
        'carrier',
        'notes',
        'coupon_code',
        'promotion_id',
        'store_credit_amount',
        'promotion_discount_details',
        'ip_address',
        'shipped_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'shipping_address' => 'array',
            'billing_address' => 'array',
            'subtotal' => 'float',
            'tax_amount' => 'float',
            'shipping_amount' => 'float',
            'discount_amount' => 'float',
            'store_credit_amount' => 'float',
            'amount_collected_courier' => 'float',
            'amount_remitted_merchant' => 'float',
            'amount_refunded' => 'float',
            'total_amount' => 'float',
            'cogs_amount' => 'float',
            'gross_profit' => 'float',
            'cash_received' => 'float',
            'change_returned' => 'float',
            'promotion_discount_details' => 'array',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    protected $appends = ['invoice_number'];

    public function getInvoiceNumberAttribute(): string
    {
        return 'INV-' . str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    public function cashierUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosRegisterSession::class, 'pos_register_session_id');
    }

    public function posRegisterSession(): BelongsTo
    {
        return $this->belongsTo(PosRegisterSession::class, 'pos_register_session_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function orderItemCostLayers(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(OrderItemCostLayer::class, OrderItem::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function redemption(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PromotionRedemption::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class)->latest();
    }

    public function latestShipment(): HasOne
    {
        return $this->hasOne(Shipment::class)->latestOfMany();
    }

    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class)->latest();
    }

    public function latestReturn(): HasOne
    {
        return $this->hasOne(OrderReturn::class)->latestOfMany();
    }

    public function timelineEvents(): HasMany
    {
        return $this->hasMany(OrderTimelineEvent::class)->orderBy('created_at', 'asc');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class)->orderBy('created_at', 'asc');
    }

    /**
     * Compute total collected amount from payment ledger.
     */
    public function getPaidAmountAttribute(): float
    {
        $ledgerPaid = (float) $this->payments()
            ->where('status', 'completed')
            ->whereIn('type', ['collection', 'partial_payment', 'full_payment', 'advance'])
            ->sum('amount');

        // Include courier collected amount if no ledger records exist yet (backward compatibility)
        if ($ledgerPaid <= 0 && (float) ($this->amount_collected_courier ?? 0) > 0) {
            return (float) $this->amount_collected_courier;
        }

        return round($ledgerPaid, 2);
    }

    /**
     * Compute outstanding remaining balance.
     */
    public function getOutstandingBalanceAttribute(): float
    {
        $total = round((float) $this->total_amount, 2);
        $paid = $this->paid_amount;
        $refunded = round((float) ($this->amount_refunded ?? 0), 2);
        $netPaid = max(0.00, round($paid - $refunded, 2));

        return max(0.00, round($total - $netPaid, 2));
    }

    /**
     * Record a new payment entry in the normalized payment ledger.
     */
    public function recordPayment(
        float $amount,
        string $paymentMethod = 'cash_on_delivery',
        string $type = 'collection',
        string $provider = 'manual',
        ?string $transactionId = null,
        ?string $notes = null,
        ?int $userId = null,
        string $status = 'completed'
    ): OrderPayment {
        $payment = $this->payments()->create([
            'payment_number' => OrderPayment::generatePaymentNumber(),
            'payment_method' => $paymentMethod,
            'provider' => $provider,
            'transaction_id' => $transactionId,
            'amount' => round($amount, 2),
            'currency' => 'BDT',
            'status' => $status,
            'type' => $type,
            'collected_at' => ($status === 'completed') ? now() : null,
            'notes' => $notes,
            'created_by_user_id' => $userId,
        ]);

        $this->recalculatePaymentStatus();

        return $payment;
    }

    /**
     * Synchronize order payment_status based on ledger totals and refunds.
     */
    public function recalculatePaymentStatus(): string
    {
        $total = round((float) $this->total_amount, 2);
        $refunded = round((float) ($this->amount_refunded ?? 0), 2);
        $paid = $this->paid_amount;

        $newStatus = 'pending';

        if ($paid > 0 && $refunded >= $paid) {
            $newStatus = 'refunded';
        } elseif ($refunded >= $total && $total > 0) {
            $newStatus = 'refunded';
        } elseif ($refunded > 0) {
            $newStatus = 'partially_refunded';
        } elseif ($paid >= $total && $total > 0) {
            $newStatus = 'paid';
        } elseif ($paid > 0) {
            $newStatus = 'partially_paid';
        }

        $this->update(['payment_status' => $newStatus]);

        return $newStatus;
    }
}

