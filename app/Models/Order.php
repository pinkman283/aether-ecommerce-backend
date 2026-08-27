<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

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
        'tracking_code',
        'carrier',
        'notes',
        'coupon_code',
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
            'total_amount' => 'float',
            'cogs_amount' => 'float',
            'gross_profit' => 'float',
            'cash_received' => 'float',
            'change_returned' => 'float',
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
}
