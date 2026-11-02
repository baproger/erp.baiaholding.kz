<?php

namespace App\Models;

use App\Models\Concerns\Auditable;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use Auditable;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'taskable_type', 'taskable_id', 'title', 'description', 'note',
        'assignee_id', 'creator_id', 'priority', 'status',
        'start_date', 'due_date', 'parent_task_id', 'checklist', 'completed_at', 'auto_completed', 'overdue_notified_at',
    ];

    protected $casts = [
        'checklist' => 'array',
        'start_date' => 'datetime',
        'due_date' => 'datetime',
        'completed_at' => 'datetime',
        'overdue_notified_at' => 'datetime',
    ];

    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    /**
     * ТЗ 02.11.2026, п.2.2: задачу «Подтвердить дизайн и расчет» нельзя закрыть,
     * пока в сделке нет сметы — ни из карточки, ни со страницы задач.
     * Автозакрытие (ЭСФ) не проверяем: к нему смета уже обязана быть.
     */
    protected static function booted(): void
    {
        static::saving(function (self $task) {
            if ($task->isDirty('status')) {
                self::assertDesignGateCanClose($task);
            }
        });
    }

    public static function assertDesignGateCanClose(self $task): void
    {
        if ($task->status !== 'done' || $task->auto_completed || $task->taskable_type !== 'deal' || ! $task->taskable_id) {
            return;
        }
        $titles = DealStage::where('stage_type', 'design')->whereNotNull('gate_task_title')->pluck('gate_task_title');
        if (! $titles->contains(fn ($t) => $t !== '' && str_starts_with((string) $task->title, $t))) {
            return;
        }
        $deal = Deal::find($task->taskable_id);
        if ($deal && ! \App\Services\StageTransitionService::hasEstimate($deal)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'stage' => 'Дизайнер должен загрузить смету — без неё «'.$task->title.'» не закрывается.',
            ]);
        }
    }
}
