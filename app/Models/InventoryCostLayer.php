<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryCostLayer extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'variant_id',
        'goods_receipt_item_id',
        'unit_cost',
        'initial_quantity',
        'remaining_quantity',
        'is_depleted',
    ];

    protected $casts = [
        'unit_cost' => 'float',
        'initial_quantity' => 'integer',
        'remaining_quantity' => 'integer',
        'is_depleted' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function goodsReceiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class);
    }

    public function orderItemCostLayers(): HasMany
    {
        return $this->hasMany(OrderItemCostLayer::class);
    }
}
