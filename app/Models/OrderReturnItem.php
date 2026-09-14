<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderReturnItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_return_id',
        'order_item_id',
        'product_id',
        'variant_id',
        'quantity_returned',
        'return_reason',
        'condition',
        'qc_status',
        'disposition',
        'restocked_quantity',
        'damaged_quantity',
        'writeoff_quantity',
        'unit_price',
        'refund_unit_price',
        'refund_subtotal',
        'qc_notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_returned' => 'integer',
            'restocked_quantity' => 'integer',
            'damaged_quantity' => 'integer',
            'writeoff_quantity' => 'integer',
            'unit_price' => 'float',
            'refund_unit_price' => 'float',
            'refund_subtotal' => 'float',
        ];
    }

    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
