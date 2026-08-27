<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_id',
        'product_id',
        'variant_id',
        'vendor_sku',
        'purchase_price',
        'currency',
        'min_order_quantity',
        'lead_time_days',
        'is_primary',
        'status',
    ];

    protected $casts = [
        'purchase_price' => 'float',
        'min_order_quantity' => 'integer',
        'lead_time_days' => 'integer',
        'is_primary' => 'boolean',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
