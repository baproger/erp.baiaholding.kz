<?php

namespace App\Models;

use App\Models\Concerns\Auditable;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deal extends Model
{
    use Auditable;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'number', 'name', 'client_name', 'company_name', 'address', 'bin', 'lot_number', 'client_id', 'responsible_user_id', 'department_id',
        'deal_stage_id', 'budget', 'deadline', 'description', 'note', 'status', 'closed_at',
    ];

    protected $casts = [
        'budget' => 'decimal:2',
        'deadline' => 'date',
        'closed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(DealStage::class, 'deal_stage_id');
    }

    public function project(): HasOne
    {
        // Latest workshop run for this deal (a deal may go through the workshop
        // more than once over its lifetime; the newest one reflects current state).
        return $this->hasOne(Project::class)->latestOfMany();
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    public function invoices(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Invoice::class, 'invoiceable');
    }

    public function expenses(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Expense::class, 'expenseable');
    }

    public function documents(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function comments(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * Successful deals = reached the "won" stage («Оплата успешно»), excluding cancelled.
     * Money counts as fact only here (payroll/analytics/dashboard): a deal in the
     * workshop or waiting on «Акт утверждение» is NOT counted until it hits «Оплата».
     */
    public function scopeWon($query)
    {
        return $query->where("status", "!=", "cancelled")
            ->whereHas("stage", fn ($s) => $s->where("is_won", true));
    }
}
