<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\DealStageLog;
use App\Models\Document;
use App\Models\Expense;
use App\Models\PreDeal;
use App\Models\Setting;
use App\Models\SupplierDebt;

/**
 * «Сопровождение сделки» (ТЗ 02.11.2026, п.6): 12 проверок правильности
 * ведения сделки и итог 🟢 Правильно / 🟡 Есть замечания / 🔴 Нарушения.
 * Каждая проверка: ok | warn | bad | na (неприменимо пока) + пояснение.
 * Итог хранится в deals.support_status (список и фильтр «красных»),
 * пересчитывается при изменениях сделки/расходов/документов/долгов и раз в
 * сутки (проверки по времени: просрочка, 48 ч, долг).
 */
class DealSupportService
{
    public const LABELS = ['green' => 'Правильно', 'yellow' => 'Есть замечания', 'red' => 'Нарушения'];

    /** @return array{status: string, checks: array<int, array<string, string>>, margin: array<string, mixed>} */
    public function check(Deal $deal): array
    {
        $deal->loadMissing('stage');
        $companyId = $deal->company_id ? (int) $deal->company_id : null;
        $funnel = DealStage::funnel($companyId);
        $order = (int) ($deal->stage?->order ?? 0);
        $orderOf = fn (string $type) => $funnel->firstWhere('stage_type', $type)?->order;
        $passed = fn (string $type) => ($o = $orderOf($type)) !== null && ($order > $o || in_array($deal->status, ['closed'], true) && $order >= $o);
        $isContractor = $deal->isContractor();
        $finished = (bool) $deal->stage?->is_won || in_array($deal->stage?->stage_type, ['esf', 'payment_won'], true);

        $expenses = Expense::where('expenseable_type', 'deal')->where('expenseable_id', $deal->id)->get();
        $checks = [];
        $add = function (string $key, string $label, string $state, string $note = '') use (&$checks) {
            $checks[] = compact('key', 'label', 'state', 'note');
        };

        // 1. Ответственный
        $add('responsible', 'Ответственный', $deal->responsible_user_id ? 'ok' : 'bad', $deal->responsible_user_id ? '' : 'не назначен');

        // 2. Договор: №, дата, количество — нарушение; файла договора нет — замечание
        $missing = StageTransitionService::missingContractFields($deal);
        $hasContractFile = Document::where('documentable_type', 'deal')->where('documentable_id', $deal->id)
            ->where('kind', 'contract')->where('is_active', true)->exists();
        if ($missing !== []) {
            $add('contract', 'Договор', 'bad', 'нет: '.implode(', ', $missing).($hasContractFile ? '' : ', файла договора'));
        } elseif (! $hasContractFile) {
            $add('contract', 'Договор', 'warn', 'не загружен файл договора («Документы» → «Загрузить договор»)');
        } else {
            $add('contract', 'Договор', 'ok');
        }

        // 3. Смета — после «Дизайн и расчет» обязательна
        if ($isContractor || $orderOf('design') === null || ! $passed('design')) {
            $add('estimate', 'Смета', StageTransitionService::hasEstimate($deal) ? 'ok' : 'na', $isContractor ? 'подряд — не нужна' : '');
        } else {
            $add('estimate', 'Смета', StageTransitionService::hasEstimate($deal) ? 'ok' : 'bad', StageTransitionService::hasEstimate($deal) ? '' : 'нет сметы дизайнера');
        }

        // 4. Срок: просрочен — нарушение; продлевали больше 1 раза — замечание
        $eff = $deal->effectiveDeadline();
        if (! $eff) {
            $add('deadline', 'Срок', 'warn', 'срок не указан');
        } elseif (! $finished && $eff->copy()->startOfDay()->lt(today()) && $deal->status !== 'cancelled') {
            $add('deadline', 'Срок', 'bad', 'просрочен с '.$eff->format('d.m.Y').($deal->extended_deadline ? ' (уже с доп. сроком)' : ' — продлите «Доп. сроком»'));
        } elseif ((int) $deal->extensions_count > 1) {
            $add('deadline', 'Срок', 'warn', 'доп. срок продлевали '.$deal->extensions_count.' раза');
        } else {
            $add('deadline', 'Срок', 'ok', $deal->extended_deadline ? 'доп. срок до '.$deal->extended_deadline->format('d.m.Y') : '');
        }

        // 5. Документы расходов
        // Документ: чек/счёт менеджера ИЛИ чек оплаты бухгалтера (уточнение 03.11.2026).
        $noDoc = $expenses->filter(fn ($e) => $e->needsDocument() && $e->status === 'confirmed' && ! $e->file_path && ! $e->confirm_file_path);
        $add('expense_docs', 'Документы расходов', $noDoc->isEmpty() ? 'ok' : 'bad',
            $noDoc->isEmpty() ? '' : 'без чека/счёта: '.$noDoc->map(fn ($e) => '#'.$e->id)->implode(', '));

        // 6. Подтверждение расходов «деньгами» — дольше 48 ч
        $stale = $expenses->filter(fn ($e) => ! $e->isDebt() && $e->status === 'pending' && $e->created_at && $e->created_at->lt(now()->subHours(48)));
        $add('expense_confirm', 'Подтверждение расходов', $stale->isEmpty() ? 'ok' : 'warn',
            $stale->isEmpty() ? '' : 'ждут бухгалтера дольше 48 ч: '.$stale->map(fn ($e) => '#'.$e->id)->implode(', '));

        // 7. Подозрительные расходы — меньше 1 000 ₸
        $tiny = $expenses->filter(fn ($e) => (float) $e->amount < 1000);
        $add('expense_small', 'Подозрительные расходы', $tiny->isEmpty() ? 'ok' : 'warn',
            $tiny->isEmpty() ? '' : 'меньше 1 000 ₸: '.$tiny->map(fn ($e) => '#'.$e->id.' ('.number_format((float) $e->amount, 0, '.', ' ').' ₸)')->implode(', '));

        // 8. Закрытие/переоткрытие
        $reopened = AuditLog::where('table_name', 'deals')->where('record_id', $deal->id)->where('action', 'reopened')->count();
        $add('reopen', 'Закрытие/переоткрытие', $reopened ? 'warn' : 'ok', $reopened ? 'сделку переоткрывали ('.$reopened.')' : '');

        // 9. Пропуск этапов — этап пройден быстрее порога (Настройки)
        $minutes = max(1, (int) Setting::get('support_fast_stage_minutes', 10));
        $fast = DealStageLog::where('deal_id', $deal->id)->whereNotNull('left_at')
            ->where('duration_seconds', '<', $minutes * 60)->pluck('stage_name')->filter()->unique();
        $add('fast_stage', 'Пропуск этапов', $fast->isEmpty() ? 'ok' : 'warn',
            $fast->isEmpty() ? '' : 'быстрее '.$minutes.' мин: '.$fast->implode(', '));

        // 10. Этап без расхода: Логистика/Сборка пройдены без расхода и без «не требуется»
        if ($isContractor) {
            $add('stage_expense', 'Этап без расхода', 'na', 'подряд');
        } else {
            $gaps = [];
            if ($passed('logistics') && ! $deal->logistics_not_required && ! $expenses->contains('type', 'delivery')) {
                $gaps[] = 'Логистика (нет «Доставки»)';
            }
            if ($passed('assembly') && ! $deal->assembly_not_required && ! $expenses->contains('type', 'assembly')) {
                $gaps[] = 'Сборка (нет «Монтажа»)';
            }
            $add('stage_expense', 'Этап без расхода', $gaps ? 'warn' : 'ok', $gaps ? implode(', ', $gaps).' — внесите расход или отметьте «не требуется»' : '');
        }

        // 11. Маржа: факт (по всем расходам) против плана из предсделки, > 10 п.п.
        $margin = $this->margin($deal, $expenses);
        $plan = PreDeal::where('deal_id', $deal->id)->value('margin');
        if ($plan === null || $isContractor) {
            $add('margin', 'Маржа', 'na', $plan === null ? 'нет предсделки — плана нет' : 'подряд');
        } elseif (! $passed('shop_gate') && ! $finished) {
            $add('margin', 'Маржа', 'na', 'оценим после «Закупа» (план '.$plan.'%)');
        } else {
            $diff = round($margin['fact_pct'] - (float) $plan, 1);
            $add('margin', 'Маржа', abs($diff) > 10 ? 'warn' : 'ok',
                'факт '.$margin['fact_pct'].'% · план '.$plan.'% ('.($diff > 0 ? '+' : '').$diff.' п.п.)');
        }

        // 12. Долги поставщикам — просрочен
        $overdueDebts = SupplierDebt::where('deal_id', $deal->id)->where('status', 'active')->whereDate('due_date', '<', today())->get();
        $add('debts', 'Долги', $overdueDebts->isEmpty() ? 'ok' : 'bad',
            $overdueDebts->isEmpty() ? '' : 'просрочен долг: '.$overdueDebts->map(fn ($d) => $d->supplier_name.' '.number_format($d->remaining(), 0, '.', ' ').' ₸')->implode(', '));

        $states = collect($checks)->pluck('state');
        $status = $states->contains('bad') ? 'red' : ($states->contains('warn') ? 'yellow' : 'green');

        return ['status' => $status, 'checks' => $checks, 'margin' => $margin];
    }

    /**
     * Фактическая маржа по ВСЕМ расходам (ТЗ п.6.1): подтверждённые + «в долг» +
     * ждущие подтверждения; отдельно — сколько из них не подтверждено.
     * Формула бонуса не меняется (по решению владельца) — это только аналитика.
     *
     * @param  \Illuminate\Support\Collection<int, Expense>  $expenses
     * @return array<string, float>
     */
    public function margin(Deal $deal, $expenses): array
    {
        $budget = (float) $deal->budget;
        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;
        $all = (float) $expenses->sum('amount');
        $unconfirmed = (float) $expenses->where('status', 'pending')->sum('amount');
        $debt = (float) $expenses->filter(fn ($e) => $e->isDebt())->sum('amount');
        $partner = PayrollService::partnerSum($budget, $deal->partner_pct);
        $remainder = $budget - round($budget * $taxRate, 2) - $all - $partner;

        return [
            'fact_pct' => $budget > 0 ? round($remainder / $budget * 100, 1) : 0.0,
            'expenses_all' => round($all, 2),
            'unconfirmed' => round($unconfirmed, 2),
            'debt' => round($debt, 2),
        ];
    }

    /** Пересчитать и сохранить итог (без событий модели — не плодить аудит). */
    public function refresh(int $dealId): ?string
    {
        $deal = Deal::find($dealId);
        if (! $deal) {
            return null;
        }
        $status = $this->check($deal)['status'];
        Deal::whereKey($dealId)->update(['support_status' => $status, 'support_checked_at' => now()]);

        return $status;
    }
}
