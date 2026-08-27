<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorPriceHistory extends Model
{
    use HasFactory;

    protected $table = 'vendor_price_history';

    protected $fillable = [
        'vendor_id',
        'product_id',
        'variant_id',
        'price',
        'effective_date',
        'changed_by_user_id',
        'notes',
    ];

    protected $casts = [
        'price' => 'float',
        'effective_date' => 'date',
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

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
