<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'variant_id',
        'movement_type',
        'quantity',
        'unit_cost',
        'total_cost',
        'balance_after',
        'reference_type',
        'reference_id',
        'user_id',
        'notes',
        'created_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_cost' => 'float',
        'total_cost' => 'float',
        'balance_after' => 'integer',
        'created_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
