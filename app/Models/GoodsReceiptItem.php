<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceiptItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'goods_receipt_id',
        'purchase_order_item_id',
        'product_id',
        'variant_id',
        'quantity_received',
        'quantity_damaged',
        'quantity_rejected',
        'unit_cost',
        'total_cost',
    ];

    protected $casts = [
        'quantity_received' => 'integer',
        'quantity_damaged' => 'integer',
        'quantity_rejected' => 'integer',
        'unit_cost' => 'float',
        'total_cost' => 'float',
    ];

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function costLayers(): HasMany
    {
        return $this->hasMany(InventoryCostLayer::class);
    }
}
