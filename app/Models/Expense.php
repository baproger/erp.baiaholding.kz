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

    protected $fillable = [
        'expenseable_type', 'expenseable_id', 'category_id', 'material_id', 'qty', 'amount', 'date',
        'responsible_user_id', 'description', 'file_path', 'type', 'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'qty' => 'decimal:2',
        'date' => 'date',
    ];

    /** Расход по материалам: позиция склада, списанная этим расходом. */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function expenseable(): MorphTo
    {
        return $this->morphTo();
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
