<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'provider',
        'consignment_id',
        'tracking_code',
        'tracking_url',
        'status',
        'courier_status_raw',
        'recipient_name',
        'recipient_phone',
        'recipient_address',
        'cod_amount',
        'courier_charge',
        'rto_charge',
        'collected_amount',
        'remitted_amount',
        'settlement_status',
        'courier_settlement_id',
        'courier_cod_fee',
        'weight',
        'delivery_area',
        'pickup_store_id',
        'notes',
        'delivery_attempts',
        'failure_reason',
        'return_reason',
        'booked_at',
        'picked_up_at',
        'in_transit_at',
        'out_for_delivery_at',
        'delivered_at',
        'returned_at',
        'cancelled_at',
        'last_synced_at',
        'raw_response',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'cod_amount' => 'float',
            'courier_charge' => 'float',
            'rto_charge' => 'float',
            'collected_amount' => 'float',
            'remitted_amount' => 'float',
            'courier_cod_fee' => 'float',
            'weight' => 'float',
            'delivery_attempts' => 'integer',
            'raw_response' => 'array',
            'metadata' => 'array',
            'booked_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'in_transit_at' => 'datetime',
            'out_for_delivery_at' => 'datetime',
            'delivered_at' => 'datetime',
            'returned_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function webhookLogs(): HasMany
    {
        return $this->hasMany(CourierWebhookLog::class, 'consignment_id', 'consignment_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class)->latest();
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CourierSettlement::class, 'courier_settlement_id');
    }

    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, ['draft', 'booked', 'pickup_pending']);
    }

    /**
     * Compute tracking link if not stored
     */
    public function getTrackingUrlAttribute(?string $value): ?string
    {
        if (!empty($value)) {
            return $value;
        }

        if ($this->provider === 'steadfast' && !empty($this->tracking_code)) {
            return 'https://steadfast.com.bd/t/' . $this->tracking_code;
        }

        if ($this->provider === 'steadfast' && !empty($this->consignment_id)) {
            return 'https://steadfast.com.bd/t/' . $this->consignment_id;
        }

        if ($this->provider === 'pathao' && !empty($this->consignment_id)) {
            return 'https://pathao.com/courier-tracking/?consignment_id=' . $this->consignment_id;
        }

        if ($this->provider === 'redx' && !empty($this->tracking_code)) {
            return 'https://redx.com.bd/track/' . $this->tracking_code;
        }

        return null;
    }
}
