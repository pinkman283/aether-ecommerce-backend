<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'city',
        'postal_code',
        'cart_items',
        'total_amount',
        'status',
        'notes',
        'source',
        'user_id',
        'converted_order_id',
    ];

    protected $casts = [
        'cart_items' => 'array',
        'total_amount' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'converted_order_id');
    }
}
