<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Долг сотрудника перед компанией. Гасится ТОЛЬКО из бонусов, фиксированной
 * суммой в месяц; оклад не затрагивается никогда.
 */
class EmployeeDebt extends Model
{
    protected $fillable = [
        'user_id', 'company_id', 'amount', 'monthly_amount', 'date',
        'payment_method', 'expense_id', 'note', 'created_by', 'closed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'monthly_amount' => 'decimal:2',
        'date' => 'date',
        'closed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(EmployeeDebtPayment::class);
    }

    /** Погашено всего. */
    public function paidSum(): float
    {
        return (float) ($this->relationLoaded('payments')
            ? $this->payments->sum('amount')
            : $this->payments()->sum('amount'));
    }

    /** Осталось. Не уходит в минус — переплатить долг нельзя. */
    public function remaining(): float
    {
        return round(max(0, (float) $this->amount - $this->paidSum()), 2);
    }
}
