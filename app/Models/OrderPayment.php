<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPayment extends Model
{
    use HasFactory;

    protected $table = 'order_payments';

    protected $fillable = [
        'order_id',
        'payment_number',
        'payment_method',
        'provider',
        'transaction_id',
        'amount',
        'currency',
        'status', // pending, completed, failed, refunded, partially_refunded
        'type',   // collection, partial_payment, full_payment, refund, advance
        'collected_at',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'amount' => 'float',
        'collected_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Generate unique payment number: PAY-YYYYMMDD-XXXX
     */
    public static function generatePaymentNumber(): string
    {
        $datePrefix = 'PAY-' . now()->format('Ymd');
        $count = self::where('payment_number', 'LIKE', "{$datePrefix}-%")->count();
        return sprintf('%s-%04d', $datePrefix, $count + 1);
    }
}
