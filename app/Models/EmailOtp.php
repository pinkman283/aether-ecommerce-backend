<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailOtp extends Model
{
    protected $fillable = [
        'email',
        'purpose',
        'otp_hash',
        'attempts',
        'expires_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'attempts' => 'integer'
    ];
}
