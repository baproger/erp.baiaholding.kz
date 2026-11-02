<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Погашение долга поставщику — реальная выплата из кассы/банка с чеком. */
class SupplierDebtPayment extends Model
{
    protected $fillable = ['supplier_debt_id', 'company_id', 'amount', 'payment_method', 'date', 'receipt_path', 'note', 'created_by'];

    protected $casts = ['amount' => 'decimal:2', 'date' => 'date:Y-m-d'];

    public function debt(): BelongsTo
    {
        return $this->belongsTo(SupplierDebt::class, 'supplier_debt_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
