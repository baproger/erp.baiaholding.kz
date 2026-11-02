<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StageTransitionService
{
    /** Подписи типов расходов — для понятного списка «что мешает» (ТЗ 02.11.2026). */
    public const EXPENSE_TYPE_LABELS = [
        'purchase' => 'Закуп', 'sheet' => 'Закуп (лист)', 'fittings' => 'Фурнитура', 'delivery' => 'Доставка',
        'assembly' => 'Монтаж', 'metal' => 'Металл', 'contractor' => 'Подрядчику', 'direct' => 'Прочий расход', 'indirect' => 'Прочий расход',
    ];

    public static function hasEstimate(Deal $deal): bool
    {
        return \App\Models\Document::where('documentable_type', 'deal')->where('documentable_id', $deal->id)
            ->where('kind', 'estimate')->where('is_active', true)->exists();
    }

    /**
     * Что мешает уйти с «Закупа» (ТЗ 02.11.2026, п.4): нужен расход «Закуп», и
     * КАЖДЫЙ расход сделки подтверждён бухгалтером (расход «в долг» с
     * поставщиком/сроком/документом — этап 2).
     *
     * @return array<int, string>
     */
    public static function purchaseGateProblems(Deal $deal): array
    {
        $expenses = \App\Models\Expense::with(['material:id,name', 'supplierDebt'])
            ->where('expenseable_type', 'deal')->where('expenseable_id', $deal->id)->orderBy('id')->get();
        $problems = [];
        if (! $expenses->contains(fn ($e) => in_array($e->type, ['purchase', 'sheet'], true))) {
            $problems[] = 'Нет расхода «Закуп» — внесите его в блоке «Расходы» сделки';
        }
        $pending = $expenses->reject(fn ($e) => self::expenseSettled($e))->values();
        foreach ($pending->take(10) as $e) {
            $label = $e->material ? 'Материал со склада: '.$e->material->name : (self::EXPENSE_TYPE_LABELS[$e->type] ?? 'Расход');
            $why = $e->isDebt() ? 'долг оформлен не полностью (поставщик/срок/документ)'
                : ($e->needsDocument() && ! $e->file_path ? 'нет чека/счёта, прикрепите документ' : 'ждёт подтверждения бухгалтера');
            $problems[] = 'Расход #'.$e->id.' '.$label.' '.number_format((float) $e->amount, 0, '.', ' ').' ₸ — '.$why;
        }
        if ($pending->count() > 10) {
            $problems[] = 'и ещё '.($pending->count() - 10).' расход(ов) ждут подтверждения';
        }

        return $problems;
    }

    /** @return array<int, string> незаполненные поля договора */
    public static function missingContractFields(Deal $deal): array
    {
        $missing = [];
        if (trim((string) $deal->bin) === '') {
            $missing[] = 'номер договора';
        }
        if (! $deal->contract_date) {
            $missing[] = 'дату договора';
        }
        if (! $deal->isContractor() && trim((string) $deal->lot_number) === '') {
            $missing[] = 'количество';
        }

        return $missing;
    }

    /**
     * Расход «закрыт» для гейта: «деньгами» — подтверждён бухгалтером; «в долг» —
     * оформлен полностью (поставщик, срок, документ), бухгалтер не нужен (п.3.4).
     */
    public static function expenseSettled(\App\Models\Expense $e): bool
    {
        if ($e->isDebt()) {
            $d = $e->supplierDebt;

            return $d && trim((string) $d->supplier_name) !== '' && $d->due_date && $d->document_path;
        }

        return $e->status === 'confirmed';
    }

    /**
     * Можно ли УЙТИ с этапа $stage вперёд (ТЗ 02.11.2026, п.1, 2, 4). Одна проверка
     * для всех путей: «Далее», перетаскивание, выбор этапа, «Отправить в цех»,
     * возврат из цеха. $checkGateTask — гейт-задача самого этапа (галочка роли).
     *
     * @throws ValidationException
     */
    public static function assertCanLeave(Deal $deal, DealStage $stage, bool $checkGateTask = true): void
    {
        $fail = fn (string $msg) => throw ValidationException::withMessages(['stage' => $msg]);

        if (! $deal->responsible_user_id) {
            $fail('Укажите ответственного менеджера — без него сделка дальше не идёт.');
        }
        if ($checkGateTask && $stage->hasGate()) {
            $open = $deal->tasks()->where('title', 'like', $stage->gate_task_title.'%')->where('status', '!=', 'done')->count();
            if ($open > 0) {
                $fail("Сначала закройте задачу «{$stage->gate_task_title}…» — гейт этапа «{$stage->name}».");
            }
        }
        // Договор (02.11.2026): с «Заключение договора» — только с номером, датой
        // и количеством (у подрядной сделки количества нет — номер и дата).
        if ($stage->stage_type === 'contract' && ($missing = self::missingContractFields($deal)) !== []) {
            $fail('Заполните в сделке (кнопка «Изменить»): '.implode(', ', $missing).' — без этого сделка не уходит с этапа «'.$stage->name.'».');
        }
        if ($stage->stage_type === 'design' && ! self::hasEstimate($deal)) {
            $fail('Дизайнер должен загрузить смету (вкладка «Документы» → «Загрузить смету»), иначе сделка не уходит с этапа «'.$stage->name.'».');
        }
        if ($stage->stage_type === 'shop_gate' && ($problems = self::purchaseGateProblems($deal)) !== []) {
            $fail('С этапа «'.$stage->name.'» дальше нельзя: '.implode('; ', $problems).'.');
        }
    }

    /**
     * Move a deal to the target stage, enforcing the current stage's checklist,
     * and the special-stage flow: «Акт утверждение» (только из цеха) → «ЭСФ» →
     * «Оплата успешно» (полная оплата НЕ обязательна — допускается частичная). Спец-этапы ищутся по названию,
     * а не по позиции — этапы можно перемещать в настройках.
     *
     * @throws ValidationException when a gate is unmet.
     */
    /**
     * Каких обязательных расходов не хватает сделке для «Логистики» (правило
     * владельца от 28.09.2026, зависит от цеха заказа):
     *  - Металл цех: Закуп + Фурнитура + По материалам (со склада);
     *  - Ағаш цех, ASU, заказа ещё нет: Закуп + Фурнитура.
     * Достаточно заявки (pending) — подтверждение бухгалтера не ждём.
     * Старые типы засчитываются: «Лист» = Закуп, «Металл» (из цеха) = материал со склада.
     *
     * @return array<int, string> русские названия недостающих расходов
     */
    public static function missingLogisticsExpenseTypes(Deal $deal): array
    {
        // Сделки без фирмы — свободно (как и раньше); подрядные в цех/логистику не идут.
        if (! $deal->company_id || $deal->isContractor()) {
            return [];
        }

        $rows = $deal->expenses()->get(['type', 'material_id']);
        $missing = [];
        if (! $rows->contains(fn ($e) => in_array($e->type, ['purchase', 'sheet'], true))) {
            $missing[] = 'Закуп';
        }
        if (! $rows->contains(fn ($e) => $e->type === 'fittings')) {
            $missing[] = 'Фурнитура';
        }
        if (self::isMetalWorkshopDeal($deal)
            && ! $rows->contains(fn ($e) => $e->material_id || $e->type === 'metal')) {
            $missing[] = 'По материалам (со склада)';
        }

        return $missing;
    }

    /** Заказ сделки идёт в металлический цех (по названию цеха — «Металл цех»). */
    private static function isMetalWorkshopDeal(Deal $deal): bool
    {
        return \App\Models\Project::where('deal_id', $deal->id)
            ->get(['workshop'])
            ->contains(fn ($p) => $p->workshop && mb_stripos($p->workshop, 'металл') !== false);
    }

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
            // Подрядная сделка: производственные этапы (дизайн, закуп, логистика,
            // сборка) ей не нужны — работу делает подрядчик (30.09.2026).
            if ($deal->isContractor() && in_array($target->stage_type, ContractorDealService::SKIPPED_STAGE_TYPES, true)) {
                throw ValidationException::withMessages([
                    'stage' => 'Подрядная сделка идёт: Договор → Акт утверждение → ЭСФ → Оплата → Тендер закрыт.',
                ]);
            }
            $actStage = DealStage::actStage($companyId);
            $esfStage = DealStage::esfStage($companyId);
            $wonStage = DealStage::wonStage($companyId);
            // Этап перед «Оплата успешно»: ЭСФ, если он есть, иначе Акт.
            $preWon = $esfStage ?? $actStage;

            // Выход вперёд (ТЗ 02.11.2026): ответственный, гейт-задача, смета на
            // «Дизайне», расходы на «Закупе». Перескочить этап с обязательной
            // проверкой нельзя (раньше перетаскиванием обходили гейты).
            if ($isForward && $current) {
                self::assertCanLeave($deal, $current);
                $skipped = DealStage::funnel($companyId)
                    ->filter(fn ($s) => $s->order > $current->order && $s->order < $target->order)
                    ->filter(fn ($s) => $s->hasGate() || in_array($s->stage_type, ['design', 'shop_gate', 'logistics'], true));
                if (! $deal->isContractor() && ($jump = $skipped->first())) {
                    throw ValidationException::withMessages([
                        'stage' => "Нельзя перескочить этап «{$jump->name}» — у него обязательная проверка. Пройдите этапы по порядку.",
                    ]);
                }
                if (! $current->hasGate() && ! empty($current->checklist)) {
                    $openTasks = $deal->tasks()->where('status', '!=', 'done')->count();
                    if ($openTasks > 0) {
                        throw ValidationException::withMessages([
                            'stage' => "Нельзя перейти на следующий этап: на этапе «{$current->name}» есть незавершённые задачи ({$openTasks}).",
                        ]);
                    }
                }
            }

            // На «Логистику» — только с обязательными расходами (по цеху заказа,
            // хватает заявки). Правило от 16.09.2026, уточнено 28.09.2026.
            if ($isForward && $target->stage_type === 'logistics'
                && ($missing = self::missingLogisticsExpenseTypes($deal)) !== []) {
                throw ValidationException::withMessages([
                    'stage' => 'На «Логистику» нельзя без обязательных расходов. Не хватает: '
                        .implode(', ', $missing).' — внесите в блоке «Расходы» сделки.',
                ]);
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
            // На won-этап — с «ЭСФ» или любого более позднего этапа (между ними
            // могут стоять свои этапы, например «Оплата»); с ранних — нельзя.
            if ($wonStage && $target->id === $wonStage->id && (! $current || ! $preWon || $current->order < $preWon->order)) {
                throw ValidationException::withMessages([
                    'stage' => 'Сначала «'.($preWon?->name ?? 'Акт утверждение').'», затем «Оплата успешно».',
                ]);
            }
            // Полная оплата для «Оплата успешно» НЕ требуется (правило от 18.07.2026):
            // сделку можно закрыть успешной и с частичной оплатой — остаток
            // виден в дебиторке на Финансах.

            // С «ЭСФ» и дальше (Оплата, Тендер закрыт) доля партнёра по
            // умолчанию — 5% от суммы договора, если её не заполнили раньше
            // (правило от 19.08.2026). Заполненную вручную долю не трогаем.
            if ($isForward && ! $deal->isContractor() && (float) $deal->partner_pct <= 0
                && (($esfStage && $target->order >= $esfStage->order) || ($wonStage && $target->id === $wonStage->id))) {
                $deal->partner_pct = 5;
            }

            // С «ЭСФ» и дальше (Оплата, Тендер закрыт) открытые задачи сделки
            // закрываются АВТОМАТИЧЕСКИ (правило от 19.08.2026): просрочка по
            // ним гаснет, на задаче остаётся знак «авто». Гейт-задача самого
            // ЭСФ создаётся ПОСЛЕ сохранения — её автозакрытие не касается.
            if ($isForward && (($esfStage && $target->order >= $esfStage->order) || ($wonStage && $target->id === $wonStage->id))) {
                $openTasks = $deal->tasks()->where('status', '!=', 'done')->get();
                foreach ($openTasks as $t) {
                    $t->update(['status' => 'done', 'completed_at' => now(), 'auto_completed' => true]);
                }
                if ($openTasks->isNotEmpty()) {
                    \App\Support\NotificationResolver::tasks($openTasks->pluck('id'));
                }
            }

            $deal->deal_stage_id = $target->id;

            $deal->save();

            // Вход на этап с настроенным гейтом → задача исполнителям
            // выбранной роли (текст/роль/срок задаются в Настройки → Этапы).
            if ($target->hasGate()) {
                $this->createGateTask($deal, $target);
            }

            if ($deal->responsible_user_id) {
                $deal->responsible?->notify(new \App\Notifications\DealStageChanged($deal, $target->name));
            }

            return $deal->fresh(['stage', 'project']);
        });
    }

    /**
     * Гейт-задача этапа: настраивается в админке (текст, роль исполнителя,
     * срок в днях). Ставится КАЖДОМУ активному сотруднику роли; пока хотя бы
     * одна не закрыта — сделка не двигается дальше (см. moveToStage); при
     * просрочке tasks:notify-overdue уведомит исполнителя.
     */
    public function createGateTask(Deal $deal, DealStage $stage): void
    {
        $title = $stage->gate_task_title.' по сделке '.$deal->number;
        if ($deal->tasks()->where('title', $title)->where('status', '!=', 'done')->exists()) {
            return; // задача уже висит — не дублируем
        }

        $assignees = User::working()->role($stage->gate_task_role ?: 'financist')->get();
        // Нет ни одного человека с ролью гейта (снабженца уволили, дизайнера ещё
        // не наняли) — задача всё равно создаётся без исполнителя, и галочку
        // ставит админ. Раньше гейт в этом случае тихо выключался (ТЗ 02.11.2026).
        if ($assignees->isEmpty()) {
            $deal->tasks()->create([
                'title' => $title, 'status' => 'new', 'priority' => 'high', 'assignee_id' => null,
                'creator_id' => $deal->responsible_user_id ?? auth()->id(),
                'start_date' => now(), 'due_date' => now()->addDays((int) $stage->gate_task_days),
            ]);

            return;
        }
        foreach ($assignees as $assignee) {
            $task = $deal->tasks()->create([
                'title' => $title,
                'status' => 'new',
                'priority' => 'high',
                'assignee_id' => $assignee->id,
                'creator_id' => $deal->responsible_user_id ?? $assignee->id,
                'start_date' => now(),
                'due_date' => now()->addDays((int) $stage->gate_task_days),
            ]);
            $assignee->notify(new \App\Notifications\TaskAssigned($task));
        }
    }
}
