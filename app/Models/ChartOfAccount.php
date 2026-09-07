<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartOfAccount extends Model
{
    use HasFactory;

    protected $table = 'chart_of_accounts';

    protected $fillable = [
        'account_code',
        'account_name',
        'account_type',
        'parent_id',
        'is_system',
        'is_active',
        'description',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ChartOfAccount::class, 'parent_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'chart_of_account_id');
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class, 'chart_of_account_id');
    }

    /**
     * Normal balance nature:
     * Assets, COGS, Expenses -> Normal Debit (Debit increases, Credit decreases)
     * Liabilities, Equity, Revenue -> Normal Credit (Credit increases, Debit decreases)
     */
    public function isNormalDebit(): bool
    {
        return in_array($this->account_type, ['asset', 'cogs', 'expense']);
    }

    /**
     * Compute current balance from posted journal lines.
     */
    public function getBalanceAttribute(): float
    {
        $lines = $this->journalLines()
            ->whereHas('journalEntry', function ($query) {
                $query->where('status', 'posted');
            })
            ->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->first();

        $debit = (float) ($lines->total_debit ?? 0);
        $credit = (float) ($lines->total_credit ?? 0);

        return $this->isNormalDebit() ? ($debit - $credit) : ($credit - $debit);
    }
}
