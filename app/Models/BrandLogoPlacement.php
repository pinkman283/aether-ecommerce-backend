<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandLogoPlacement extends Model
{
    use HasFactory;

    protected $table = 'brand_logo_placements';

    protected $fillable = [
        'logo_id',
        'placement',
    ];

    public function logo(): BelongsTo
    {
        return $this->belongsTo(BrandLogo::class, 'logo_id');
    }
}
