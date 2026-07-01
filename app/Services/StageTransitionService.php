<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StageTransitionService
{
    public function __construct(private ProjectService $projects) {}

    /**
     * Move a deal to the target stage, enforcing the current stage's checklist,
     * and auto-creating a project when the target stage is a "won" stage.
     *
     * @throws ValidationException when the current stage checklist is unmet.
     */
    public function moveToStage(Deal $deal, DealStage $target): Deal
    {
        return DB::transaction(function () use ($deal, $target) {
            $deal->loadMissing('stage');
            $current = $deal->stage;

            // Moving forward (to a higher-order stage) requires the current
            // stage's checklist to be satisfied. We treat any incomplete task
            // attached to the deal as an unmet checklist when the current stage
            // defines checklist items.
            $isForward = $current && $target->order > $current->order;

            if ($isForward && ! empty($current->checklist)) {
                $openTasks = $deal->tasks()->where('status', '!=', 'done')->count();
                if ($openTasks > 0) {
                    throw ValidationException::withMessages([
                        'stage' => "Нельзя перейти на следующий этап: на этапе «{$current->name}» есть незавершённые задачи ({$openTasks}).",
                    ]);
                }
            }

            $deal->deal_stage_id = $target->id;

            if ($target->is_won) {
                $deal->status = 'closed';
                $deal->closed_at = now();
            }

            $deal->save();

            if ($deal->responsible_user_id) {
                $deal->responsible?->notify(new \App\Notifications\DealStageChanged($deal, $target->name));
            }

            return $deal->fresh(['stage', 'project']);
        });
    }
}
