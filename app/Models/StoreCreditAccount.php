<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreCreditAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'balance',
        'total_credited',
        'total_debited',
        'is_frozen',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'total_credited' => 'decimal:2',
            'total_debited' => 'decimal:2',
            'is_frozen' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(StoreCreditTransaction::class, 'account_id');
    }
}
