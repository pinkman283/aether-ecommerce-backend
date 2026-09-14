<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrandLogo extends Model
{
    use HasFactory;

    protected $table = 'brand_logos';

    protected $fillable = [
        'name',
        'image_url',
    ];

    public function placements(): HasMany
    {
        return $this->hasMany(BrandLogoPlacement::class, 'logo_id');
    }
}
