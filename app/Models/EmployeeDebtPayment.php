<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Погашение долга сотрудника за конкретный месяц (YYYY-MM). */
class EmployeeDebtPayment extends Model
{
    protected $fillable = ['employee_debt_id', 'month', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function debt(): BelongsTo
    {
        return $this->belongsTo(EmployeeDebt::class, 'employee_debt_id');
    }
}
