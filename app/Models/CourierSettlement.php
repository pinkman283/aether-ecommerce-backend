<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourierSettlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'settlement_number',
        'provider',
        'settlement_date',
        'total_cod_collected',
        'delivery_fees',
        'return_fees',
        'other_deductions',
        'expected_payout',
        'actual_payout',
        'variance',
        'bank_account_id',
        'journal_entry_id',
        'status',
        'notes',
        'reconciled_by_user_id',
        'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'total_cod_collected' => 'float',
            'delivery_fees' => 'float',
            'return_fees' => 'float',
            'other_deductions' => 'float',
            'expected_payout' => 'float',
            'actual_payout' => 'float',
            'variance' => 'float',
            'settlement_date' => 'date',
            'reconciled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CourierSettlementItem::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reconciledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_user_id');
    }
}
