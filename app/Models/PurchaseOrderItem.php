<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'variant_id',
        'product_name',
        'sku',
        'unit_cost',
        'quantity_ordered',
        'quantity_received',
        'quantity_damaged',
        'quantity_rejected',
        'subtotal',
    ];

    protected $casts = [
        'unit_cost' => 'float',
        'quantity_ordered' => 'integer',
        'quantity_received' => 'integer',
        'quantity_damaged' => 'integer',
        'quantity_rejected' => 'integer',
        'subtotal' => 'float',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function goodsReceiptItems(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }
}
