<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemCostLayer extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'order_item_id',
        'inventory_cost_layer_id',
        'quantity_consumed',
        'unit_cost',
        'total_cost',
        'created_at',
    ];

    protected $casts = [
        'quantity_consumed' => 'integer',
        'unit_cost' => 'float',
        'total_cost' => 'float',
        'created_at' => 'datetime',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function inventoryCostLayer(): BelongsTo
    {
        return $this->belongsTo(InventoryCostLayer::class);
    }
}
