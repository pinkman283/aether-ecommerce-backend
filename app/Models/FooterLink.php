<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FooterLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'footer_column_id',
        'title',
        'url',
        'is_external',
        'open_in_new_tab',
        'column_group',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_external' => 'boolean',
            'open_in_new_tab' => 'boolean',
            'sort_order' => 'integer',
            'footer_column_id' => 'integer',
        ];
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(FooterColumn::class, 'footer_column_id');
    }
}
