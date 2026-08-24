<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ошибка сайта. Записи НЕ удаляются: ни маршрута, ни метода удаления нет —
 * журнал только пополняется и читается админом (Аудит → Ошибки).
 */
class ErrorLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'exception', 'message', 'file', 'line', 'url', 'method', 'user_id', 'ip', 'trace', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
