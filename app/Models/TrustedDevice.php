<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Устройство, на котором сотрудник уже вводил код входа (30 дней). */
class TrustedDevice extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'ip', 'user_agent', 'last_used_at', 'expires_at'];

    protected $casts = ['last_used_at' => 'datetime', 'expires_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
