<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    /**
     * Метка «в значении лежит СНИМОК всей записи», а не одно поле.
     * Ставится при создании и удалении: там менять нечего, важно «с чем
     * появилась запись» и «что именно исчезло».
     */
    public const SNAPSHOT = '*';

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'ip', 'user_agent', 'table_name', 'record_id',
        'action', 'field_name', 'old_value', 'new_value', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
