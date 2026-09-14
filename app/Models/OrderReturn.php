<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_number',
        'order_id',
        'shipment_id',
        'customer_id',
        'return_type',
        'status',
        'return_reason',
        'order_total_snapshot',
        'product_subtotal_snapshot',
        'shipping_charge_snapshot',
        'amount_collected_courier',
        'amount_expected_from_customer',
        'refund_amount',
        'refund_method',
        'refund_status',
        'courier_delivery_fee',
        'courier_rto_fee',
        'courier_tracking_code',
        'inspection_status',
        'received_at',
        'inspected_at',
        'resolved_at',
        'notes',
        'created_by_user_id',
        'inspected_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'order_total_snapshot' => 'float',
            'product_subtotal_snapshot' => 'float',
            'shipping_charge_snapshot' => 'float',
            'amount_collected_courier' => 'float',
            'amount_expected_from_customer' => 'float',
            'refund_amount' => 'float',
            'courier_delivery_fee' => 'float',
            'courier_rto_fee' => 'float',
            'received_at' => 'datetime',
            'inspected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function inspectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderReturnItem::class);
    }
}
