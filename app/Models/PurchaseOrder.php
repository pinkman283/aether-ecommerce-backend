<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'po_number',
        'vendor_id',
        'status',
        'order_date',
        'expected_delivery_date',
        'subtotal',
        'shipping_cost',
        'tax_amount',
        'other_costs',
        'total_amount',
        'notes',
        'created_by_user_id',
        'approved_by_user_id',
        'approved_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_delivery_date' => 'date',
        'subtotal' => 'float',
        'shipping_cost' => 'float',
        'tax_amount' => 'float',
        'other_costs' => 'float',
        'total_amount' => 'float',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
