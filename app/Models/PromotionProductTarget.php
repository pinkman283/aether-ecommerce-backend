<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionProductTarget extends Model
{
    use HasFactory;

    protected $fillable = [
        'promotion_id',
        'target_type',
        'target_id',
        'is_exclusion',
    ];

    protected function casts(): array
    {
        return [
            'is_exclusion' => 'boolean',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
