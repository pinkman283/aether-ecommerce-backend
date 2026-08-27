<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosRegisterSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'pos_register_id',
        'user_id',
        'opened_at',
        'closed_at',
        'opening_balance',
        'cash_sales_amount',
        'card_sales_amount',
        'mobile_sales_amount',
        'cash_in_amount',
        'cash_out_amount',
        'cash_refunds_amount',
        'expected_cash_balance',
        'actual_closing_cash',
        'cash_difference',
        'closing_notes',
        'status',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_balance' => 'float',
        'cash_sales_amount' => 'float',
        'card_sales_amount' => 'float',
        'mobile_sales_amount' => 'float',
        'cash_in_amount' => 'float',
        'cash_out_amount' => 'float',
        'cash_refunds_amount' => 'float',
        'expected_cash_balance' => 'float',
        'actual_closing_cash' => 'float',
        'cash_difference' => 'float',
    ];

    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'pos_register_id');
    }

    public function posRegister(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'pos_register_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'pos_register_session_id');
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(PosCashMovement::class);
    }

    public function recalculateExpectedCash(): float
    {
        $expected = $this->opening_balance
            + $this->cash_sales_amount
            + $this->cash_in_amount
            - $this->cash_out_amount
            - $this->cash_refunds_amount;
        
        $this->expected_cash_balance = $expected;
        return $expected;
    }
}
