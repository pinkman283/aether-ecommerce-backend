<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminInvitation extends Model
{
    protected $fillable = [
        'email',
        'role',
        'token',
        'expires_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime'
    ];
}
