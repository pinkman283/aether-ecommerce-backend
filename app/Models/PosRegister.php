<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PosRegister extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'status',
    ];

    public function sessions(): HasMany
    {
        return $this->hasMany(PosRegisterSession::class);
    }

    public function activeSession(): HasOne
    {
        return $this->hasOne(PosRegisterSession::class)->where('status', 'open')->latestOfMany();
    }
}
