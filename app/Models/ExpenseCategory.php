<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    /**
     * Служебная категория выдач сотрудникам (авансы, долги, выплата ЗП).
     * В итог «Расходы» не входит (зарплата там строкой «Зарплата»), кассу
     * уменьшает; переименовывать/удалять её нельзя — на имя завязан код.
     */
    public const EMPLOYEE = 'Расходы по сотрудникам';

    protected $fillable = ['name', 'parent_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class, 'parent_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ExpenseCategoryTranslation::class);
    }
}
