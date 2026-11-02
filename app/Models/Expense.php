<?php

namespace App\Models;

use App\Models\Concerns\Auditable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use Auditable;
    use SoftDeletes;

    /** Перечисление подрядчику по подрядной сделке (ContractorDealService). */
    public const TYPE_CONTRACTOR = 'contractor';

    protected $fillable = [
        'expenseable_type', 'expenseable_id', 'company_id', 'category_id', 'material_id', 'qty', 'amount', 'date',
        'responsible_user_id', 'employee_id', 'employee_payout', 'description', 'file_path', 'confirm_file_path', 'type', 'status',
        'payment_method', 'confirmed_by', 'confirmed_at', 'funding',
    ];

    /** Взят у поставщика в долг (ТЗ 02.11.2026, п.3): кассу не трогает, гасится в «Долг сделкам». */
    public const FUNDING_DEBT = 'debt';

    public function isDebt(): bool
    {
        return $this->funding === self::FUNDING_DEBT;
    }

    /**
     * Нужен ли расходу документ (чек/счёт), чтобы бухгалтер его подтвердил
     * (ТЗ 02.11.2026, п.3.2). Не нужен автоматическим: списание со склада,
     * металл из цеха, перечисление подрядчику, выплаты сотрудникам.
     */
    public function needsDocument(): bool
    {
        return in_array($this->expenseable_type, ['deal', 'project'], true)
            && ! $this->material_id && ! $this->employee_id
            && ! in_array($this->type, ['metal', 'contractor'], true);
    }

    public function supplierDebt(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SupplierDebt::class);
    }

    protected $casts = [
        'amount' => 'decimal:2',
        'qty' => 'decimal:2',
        'date' => 'date',
        'confirmed_at' => 'datetime',
    ];

    /** Бухгалтер, подтвердивший расход (нал/банк + чек). */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** Расход по материалам: позиция склада, списанная этим расходом. */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function expenseable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Сотрудник, которому выдали деньги (аванс / долг). */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}
