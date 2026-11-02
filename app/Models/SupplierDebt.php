<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Долг поставщику по сделке (ТЗ 02.11.2026, п.3.5): создаётся сам из расхода
 * «в долг», гасится выплатами из кассы (SupplierDebtPayment). Ручного ввода нет.
 */
class SupplierDebt extends Model
{
    use Auditable;

    protected $fillable = ['company_id', 'deal_id', 'expense_id', 'supplier_name', 'supplier_contact', 'description',
        'amount', 'paid', 'due_date', 'document_path', 'status', 'created_by', 'paid_at'];

    // date:Y-m-d — без часового пояса: иначе полночь Алматы уходила в браузер как «вчера» (UTC).
    protected $casts = ['amount' => 'decimal:2', 'paid' => 'decimal:2', 'due_date' => 'date:Y-m-d', 'paid_at' => 'datetime'];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierDebtPayment::class);
    }

    public function remaining(): float
    {
        return max(0, round((float) $this->amount - (float) $this->paid, 2));
    }

    /** Активен / Просрочен / Погашен — для фильтров и бейджей. */
    public function state(): string
    {
        if ($this->status === 'paid') {
            return 'paid';
        }

        return $this->due_date && $this->due_date->lt(today()) ? 'overdue' : 'active';
    }
}
