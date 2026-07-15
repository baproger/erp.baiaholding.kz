<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Корректировка ЗП: absence (отгул) / sick (больничный) / fine (штраф) —
 * удержания; bonus (премия) — доплата. amount всегда положительная,
 * знак определяется типом.
 */
class PayrollAdjustment extends Model
{
    public const TYPES = ['absence', 'sick', 'fine', 'bonus'];

    /** Типы-удержания (минус к выплате). */
    public const DEDUCTIONS = ['absence', 'sick', 'fine'];

    protected $fillable = ['user_id', 'type', 'days', 'amount', 'date', 'note', 'created_by'];

    protected $casts = [
        'days' => 'decimal:2',
        'amount' => 'decimal:2',
        'date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
