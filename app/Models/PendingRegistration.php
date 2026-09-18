<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingRegistration extends Model
{
    protected $fillable = [
        'email',
        'name',
        'password_hash',
        'phone',
        'otp_hash',
        'attempts',
        'expires_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'attempts' => 'integer'
    ];
}
