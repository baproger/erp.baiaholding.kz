<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StageTransitionService
{
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

            // Post-workshop stages are reachable only through the workshop flow:
            //   «Акт утверждение» (2nd-from-last) — only via the Цех "АКТ" action.
            //   «Оплата успешно»  (last, is_won)  — only from «Акт утверждение».
            $active = DealStage::where('is_active', true)->orderBy('order')->get();
            $returnStage = $active->slice(-2, 1)->first();
            $wonStage = $active->last();
            if ($returnStage && $target->id === $returnStage->id) {
                throw ValidationException::withMessages([
                    'stage' => 'На «Акт утверждение» заказ попадает только из цеха (кнопка «АКТ»).',
                ]);
            }
            if ($wonStage && $target->id === $wonStage->id && (! $current || ! $returnStage || $current->id !== $returnStage->id)) {
                throw ValidationException::withMessages([
                    'stage' => 'Сначала «Акт утверждение», затем «Оплата успешно».',
                ]);
            }
            // «Оплата успешно» requires the deal to be fully paid (paid income == deal sum).
            if ($wonStage && $target->id === $wonStage->id) {
                $paid = (float) \App\Models\Payment::whereHas('invoice', fn ($q) => $q->where('invoiceable_type', 'deal')->where('invoiceable_id', $deal->id))->sum('amount');
                $remainder = round((float) $deal->budget - $paid, 2);
                if ($remainder > 0.009) {
                    throw ValidationException::withMessages([
                        'stage' => 'Нельзя перевести на «Оплата успешно»: остаток оплаты '.number_format($remainder, 0, '.', ' ').' ₸ (сумма сделки '.number_format((float) $deal->budget, 0, '.', ' ').', оплачено '.number_format($paid, 0, '.', ' ').'). Внесите полную оплату.',
                    ]);
                }
            }

            $deal->deal_stage_id = $target->id;

            $deal->save();

            if ($deal->responsible_user_id) {
                $deal->responsible?->notify(new \App\Notifications\DealStageChanged($deal, $target->name));
            }

            return $deal->fresh(['stage', 'project']);
        });
    }
}
