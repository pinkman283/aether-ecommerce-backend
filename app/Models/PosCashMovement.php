<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosCashMovement extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'pos_register_session_id',
        'type',
        'amount',
        'reason',
        'user_id',
        'created_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'created_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosRegisterSession::class, 'pos_register_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
