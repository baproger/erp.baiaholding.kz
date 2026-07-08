<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StageTransitionService
{
    /**
     * Move a deal to the target stage, enforcing the current stage's checklist,
     * and the special-stage flow: «Акт утверждение» (только из цеха) → «ЭСФ» →
     * «Оплата успешно» (только при полной оплате). Спец-этапы ищутся по названию,
     * а не по позиции — этапы можно перемещать в настройках.
     *
     * @throws ValidationException when a gate is unmet.
     */
    public function moveToStage(Deal $deal, DealStage $target): Deal
    {
        return DB::transaction(function () use ($deal, $target) {
            $deal->loadMissing('stage');
            $current = $deal->stage;

            $isForward = $current && $target->order > $current->order;

            // Спец-этапы ВОРОНКИ КОМПАНИИ этой сделки (у BAIA и ASU свои).
            $companyId = $deal->company_id ? (int) $deal->company_id : null;
            if ($target->company_id && (int) $target->company_id !== $companyId) {
                throw ValidationException::withMessages([
                    'stage' => 'Этап принадлежит воронке другой компании.',
                ]);
            }
            $actStage = DealStage::actStage($companyId);
            $esfStage = DealStage::esfStage($companyId);
            $wonStage = DealStage::wonStage($companyId);
            // Этап перед «Оплата успешно»: ЭСФ, если он есть, иначе Акт.
            $preWon = $esfStage ?? $actStage;

            // Галочка бухгалтера: с «Акта» дальше — только когда закрыта задача
            // «Выставить акт…» (3 дня), с «ЭСФ» — «Выставить ЭСФ…» (30 дней).
            // Прочие открытые задачи сделку не держат. Для остальных этапов
            // работает общий checklist-гейт (все задачи должны быть закрыты).
            if ($isForward && $current) {
                $stageTaskPrefix = null;
                if ($actStage && $current->id === $actStage->id) {
                    $stageTaskPrefix = 'Выставить акт';
                } elseif ($esfStage && $current->id === $esfStage->id) {
                    $stageTaskPrefix = 'Выставить ЭСФ';
                }

                if ($stageTaskPrefix) {
                    $open = $deal->tasks()->where('title', 'like', $stageTaskPrefix.'%')->where('status', '!=', 'done')->count();
                    if ($open > 0) {
                        throw ValidationException::withMessages([
                            'stage' => "Сначала закройте задачу «{$stageTaskPrefix}…» — галочка бухгалтера на этапе «{$current->name}».",
                        ]);
                    }
                } elseif (! empty($current->checklist)) {
                    $openTasks = $deal->tasks()->where('status', '!=', 'done')->count();
                    if ($openTasks > 0) {
                        throw ValidationException::withMessages([
                            'stage' => "Нельзя перейти на следующий этап: на этапе «{$current->name}» есть незавершённые задачи ({$openTasks}).",
                        ]);
                    }
                }
            }

            // Этапы «Акт утверждение», «ЭСФ», «Оплата успешно» двигает ТОЛЬКО
            // бухгалтер (financist) или админ. Менеджер довозит сделку ДО акта
            // (Сборка → Акт), дальше — не может; директор тоже не двигает.
            $user = auth()->user();
            $accountant = ! $user || $user->hasAnyRole(['admin', 'financist']);
            $postActIds = collect([$actStage?->id, $esfStage?->id, $wonStage?->id])->filter();
            if (! $accountant && $current && $postActIds->contains($current->id)) {
                throw ValidationException::withMessages([
                    'stage' => 'После «Акт утверждение» сделку двигает только бухгалтер или админ.',
                ]);
            }
            if (! $accountant && $postActIds->contains($target->id) && (! $actStage || $target->id !== $actStage->id)) {
                throw ValidationException::withMessages([
                    'stage' => 'Этапы «ЭСФ» и «Оплата успешно» переводит только бухгалтер или админ.',
                ]);
            }

            if ($esfStage && $target->id === $esfStage->id && (! $current || ! $actStage || $current->id !== $actStage->id)) {
                throw ValidationException::withMessages([
                    'stage' => 'На «ЭСФ» можно перейти только с этапа «Акт утверждение».',
                ]);
            }
            if ($wonStage && $target->id === $wonStage->id && (! $current || ! $preWon || $current->id !== $preWon->id)) {
                throw ValidationException::withMessages([
                    'stage' => 'Сначала «'.($preWon?->name ?? 'Акт утверждение').'», затем «Оплата успешно».',
                ]);
            }
            // «Оплата успешно» requires the deal to be fully paid (paid income == deal sum).
            if ($wonStage && $target->id === $wonStage->id) {
                $paid = (float) \App\Models\Payment::whereHas('invoice', fn ($q) => $q->where('invoiceable_type', 'deal')->where('invoiceable_id', $deal->id))->sum('amount');
                $remainder = round((float) $deal->budget - $paid, 2);
                if ($remainder > 0.009) {
                    throw ValidationException::withMessages([
                        'stage' => 'Нельзя перевести на «Оплата успешно»: остаток оплаты '.number_format($remainder, 0, '.', ' ').' ₸ (сумма договора '.number_format((float) $deal->budget, 0, '.', ' ').', оплачено '.number_format($paid, 0, '.', ' ').'). Внесите полную оплату.',
                    ]);
                }
            }

            $deal->deal_stage_id = $target->id;

            $deal->save();

            // На «Акт утверждение» — задача бухгалтеру, галочка со сроком 3 дня.
            if ($actStage && $target->id === $actStage->id) {
                $this->createFinancistTask($deal, 'Выставить акт по сделке '.$deal->number, 3);
            }
            // На «ЭСФ» — задача бухгалтеру, галочка со сроком 30 дней.
            if ($esfStage && $target->id === $esfStage->id) {
                $this->createFinancistTask($deal, 'Выставить ЭСФ по сделке '.$deal->number, 30);
            }

            if ($deal->responsible_user_id) {
                $deal->responsible?->notify(new \App\Notifications\DealStageChanged($deal, $target->name));
            }

            return $deal->fresh(['stage', 'project']);
        });
    }

    /**
     * Задача-уведомление финансисту (бухгалтеру) со сроком: «Акт» — 3 дня,
     * «ЭСФ» — 30 дней. Пока задача не закрыта галочкой, сделка не двигается
     * дальше (checklist-гейт этапа); при просрочке tasks:notify-overdue
     * уведомит исполнителя-финансиста.
     */
    public function createFinancistTask(Deal $deal, string $title, int $days): void
    {
        if ($deal->tasks()->where('title', $title)->where('status', '!=', 'done')->exists()) {
            return; // задача уже висит — не дублируем
        }

        $financists = User::where('is_active', true)->role('financist')->get();
        foreach ($financists as $fin) {
            $task = $deal->tasks()->create([
                'title' => $title,
                'status' => 'new',
                'priority' => 'high',
                'assignee_id' => $fin->id,
                'creator_id' => $deal->responsible_user_id ?? $fin->id,
                'start_date' => now(),
                'due_date' => now()->addDays($days),
            ]);
            $fin->notify(new \App\Notifications\TaskAssigned($task));
        }
    }
}
