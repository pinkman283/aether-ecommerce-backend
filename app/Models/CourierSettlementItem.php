<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierSettlementItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'courier_settlement_id',
        'shipment_id',
        'order_id',
        'consignment_id',
        'tracking_code',
        'cod_collected',
        'delivery_fee',
        'rto_fee',
        'cod_fee',
        'other_fee',
        'net_payout',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'cod_collected' => 'float',
            'delivery_fee' => 'float',
            'rto_fee' => 'float',
            'cod_fee' => 'float',
            'other_fee' => 'float',
            'net_payout' => 'float',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CourierSettlement::class, 'courier_settlement_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
