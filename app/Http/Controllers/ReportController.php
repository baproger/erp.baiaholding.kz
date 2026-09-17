<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Реестр сделок (договоров) — сводная таблица «как в Excel» для руководства:
 * каждая строка = сделка со всей денежной математикой (та же формула, что на
 * карточке сделки и в ЗП: налог → остаток → маржа → бонус → фирма) + Итого.
 * Только admin/director — здесь видны бонусы всех менеджеров.
 */
class ReportController extends Controller
{
    /**
     * «План / Факт» по предсделкам (правило от 13.09.2026): что менеджер
     * предпосчитал в лоте (расходы, остаток, маржа) против факта по сделке —
     * и разница. ТОЛЬКО админ и директор: это оценка честности расчётов.
     */
    public function planFact(Request $request): Response
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'director']), 403);

        return Inertia::render('Reports/PlanFact', \App\Support\ReportCache::remember(
            $request, 'planfact', fn () => $this->buildPlanFact($request)));
    }

    /** @return array<string, mixed> */
    private function buildPlanFact(Request $request): array
    {
        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;
        $companyId = \App\Support\CurrentCompany::id() ?: null;

        // Фильтры: менеджер (кто вёл лот), период внесения лота, статус сделки.
        $managerId = $request->integer('manager') ?: null;
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        $status = in_array($s = $request->string('status')->toString(), ['won', 'active'], true) ? $s : null;

        $lots = \App\Models\PreDeal::query()
            ->whereNotNull('deal_id')
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))
            ->when($managerId, fn ($q, $m) => $q->where('user_id', $m))
            ->when($from, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($to, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->with(['deal' => fn ($q) => $q->withTrashed()->with(['stage:id,name,color,is_won', 'responsible:id,name']),
                'user:id,name'])
            ->latest('id')->limit(300)->get();

        // Статус сделки: «выигранные» / «в работе» — по флагу этапа.
        if ($status) {
            $lots = $lots->filter(fn ($l) => $l->deal
                && (bool) $l->deal->stage?->is_won === ($status === 'won'))->values();
        }

        $dealIds = $lots->pluck('deal_id')->filter();
        $factExp = \App\Models\Expense::where('status', 'confirmed')
            ->where('expenseable_type', 'deal')->whereIn('expenseable_id', $dealIds)
            ->groupBy('expenseable_id')->selectRaw('expenseable_id as did, SUM(amount) as v')->pluck('v', 'did');

        $rows = $lots->filter(fn ($l) => $l->deal && ! $l->deal->deleted_at)->map(function ($l) use ($factExp, $taxRate) {
            $d = $l->deal;
            $budget = (float) $d->budget;

            // ПЛАН — цифры менеджера из лота (справочные). Бонус — авто-ставка
            // по шкале от плановой маржи, «фирме» = остаток − бонус.
            $planExpense = round((float) $l->purchase_price + (float) $l->delivery + (float) $l->assembly + (float) $l->commission, 2);
            $planRemainder = (float) $l->remainder;
            $planMargin = (float) $l->margin;
            $planBonus = round($planRemainder > 0 ? PayrollService::effectiveBonusRate($planMargin) * $planRemainder : 0, 2);
            $planNet = round($planRemainder - $planBonus, 2);

            // ФАКТ — по сделке на текущий момент (подтверждённые расходы).
            $factExpense = (float) ($factExp[$d->id] ?? 0);
            $tax = round($budget * $taxRate, 2);
            $partner = PayrollService::partnerSum($budget, $d->partner_pct);
            $factRemainder = round($budget - $tax - $factExpense - $partner, 2);
            $factMargin = PayrollService::marginPct($budget, $factRemainder);
            // Факт-бонус: полный (как при полной оплате), с ручным % финансиста.
            $factBonus = PayrollService::marginBonus($budget, $factRemainder, $tax,
                $d->bonus_rate_override !== null ? (float) $d->bonus_rate_override : null);
            $factNet = round($factRemainder - $factBonus, 2);

            return [
                'deal_id' => $d->id,
                'number' => $d->number,
                'customer' => $d->company_name,
                'manager' => $l->user?->name ?? $d->responsible?->name,
                'stage' => $d->stage?->name,
                'stage_color' => $d->stage?->color,
                'is_won' => (bool) $d->stage?->is_won,
                'budget' => $budget,
                'plan' => ['expense' => $planExpense, 'remainder' => $planRemainder, 'margin' => $planMargin,
                    'bonus' => $planBonus, 'net' => $planNet,
                    'tax' => (float) $l->tax, 'partner' => (float) $l->partner_sum,
                    'sum' => (float) $l->contract_sum],
                'fact' => ['expense' => $factExpense, 'remainder' => $factRemainder, 'margin' => $factMargin,
                    'bonus' => $factBonus, 'net' => $factNet,
                    'tax' => $tax, 'partner' => $partner, 'sum' => $budget],
                // Разница = факт − план: расходы «+» — потратили больше плана;
                // маржа/чистая «−» — фирме остаётся меньше обещанного.
                'diff' => [
                    'expense' => round($factExpense - $planExpense, 2),
                    'remainder' => round($factRemainder - $planRemainder, 2),
                    'margin' => round($factMargin - $planMargin, 1),
                    'net' => round($factNet - $planNet, 2),
                ],
            ];
        })->values();

        return [
            'rows' => $rows,
            'totals' => [
                'plan_expense' => (float) $rows->sum(fn ($r) => $r['plan']['expense']),
                'fact_expense' => (float) $rows->sum(fn ($r) => $r['fact']['expense']),
                'plan_remainder' => (float) $rows->sum(fn ($r) => $r['plan']['remainder']),
                'fact_remainder' => (float) $rows->sum(fn ($r) => $r['fact']['remainder']),
                'plan_bonus' => (float) $rows->sum(fn ($r) => $r['plan']['bonus']),
                'fact_bonus' => (float) $rows->sum(fn ($r) => $r['fact']['bonus']),
                'plan_net' => (float) $rows->sum(fn ($r) => $r['plan']['net']),
                'fact_net' => (float) $rows->sum(fn ($r) => $r['fact']['net']),
            ],
            'filters' => ['manager' => $managerId, 'from' => $from, 'to' => $to, 'status' => $status],
            // Менеджеры для фильтра — только те, у чьих лотов есть сделки.
            'managers' => \App\Models\User::whereIn('id', \App\Models\PreDeal::whereNotNull('deal_id')
                    ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))->select('user_id'))
                ->orderBy('name')->get(['id', 'name'])->toArray(),
        ];
    }

    public function deals(Request $request): Response
    {
        // Права — ДО кеша; сам расчёт — в buildDeals() и живёт 5 минут
        // (сбрасывается любым изменением денег, см. ReportCache).
        $user = $request->user();
        abort_unless($user->hasAnyRole(['admin', 'director', 'financist']) || $user->hasRole('manager'), 403);

        return Inertia::render('Reports/Deals', \App\Support\ReportCache::remember($request, 'deals', fn () => $this->buildDeals($request)));
    }

    /** @return array<string, mixed> */
    private function buildDeals(Request $request): array
    {
        $user = $request->user();
        // Руководство видит отчёт целиком (бонусы всех менеджеров), МОП —
        // ТОЛЬКО свои сделки: свой срез «сколько сделал за месяц и где стоит».
        $isLeadership = $user->hasAnyRole(['admin', 'director', 'financist']);

        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;

        $search = $request->string('search')->toString();
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        // Чужой ?manager= менеджеру не поможет: он всегда прибит к себе.
        $managerId = $isLeadership ? ($request->integer('manager') ?: null) : $user->id;
        $stageId = $request->integer('stage') ?: null;
        // Источник сделки (ОМ / ЗЦП / ИОИ / СК…) — только из справочника.
        $source = in_array($src = $request->string('source')->toString(), Deal::SOURCES, true) ? $src : null;

        $deals = Deal::forCurrentCompany()
            ->where('status', '!=', 'cancelled')
            ->with(['responsible:id,name', 'stage:id,name,color,order,is_won,stage_type'])
            ->when($search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('number', 'like', "%{$s}%")->orWhere('company_name', 'like', "%{$s}%")
                ->orWhere('client_name', 'like', "%{$s}%")->orWhere('bin', 'like', "%{$s}%")
                ->orWhere('address', 'like', "%{$s}%")))
            ->when($managerId, fn ($q, $m) => $q->where('responsible_user_id', $m))
            ->when($stageId, fn ($q, $s) => $q->where('deal_stage_id', $s))
            ->when($source, fn ($q, $s) => $q->where('source', $s))
            // Период — по дате ДОГОВОРА (без неё — по дате создания): та же
            // логика, что у фильтра «Месяц» на Финансах, цифры совпадают.
            ->when($from || $to, fn ($q) => $q->where(fn ($w) => $w
                ->where(fn ($c) => $c->whereNotNull('contract_date')
                    ->when($from, fn ($q2, $d) => $q2->whereDate('contract_date', '>=', $d))
                    ->when($to, fn ($q2, $d) => $q2->whereDate('contract_date', '<=', $d)))
                ->orWhere(fn ($c) => $c->whereNull('contract_date')
                    ->when($from, fn ($q2, $d) => $q2->whereDate('created_at', '>=', $d))
                    ->when($to, fn ($q2, $d) => $q2->whereDate('created_at', '<=', $d)))))
            ->latest()
            ->get(['id', 'number', 'bin', 'company_name', 'address', 'client_name', 'lot_number', 'unit',
                'budget', 'partner_pct', 'bonus_rate_override', 'deadline', 'deal_stage_id', 'responsible_user_id', 'status', 'created_at', 'contract_date']);

        // Оплачено по сделке — платежи по её счетам (одним запросом на всех).
        $paidByDeal = Payment::join('invoices', 'payments.invoice_id', '=', 'invoices.id')->whereNull('invoices.deleted_at')
            ->where('invoices.invoiceable_type', 'deal')
            ->whereIn('invoices.invoiceable_id', $deals->pluck('id'))
            ->groupBy('invoices.invoiceable_id')
            ->selectRaw('invoices.invoiceable_id as deal_id, sum(payments.amount) as paid')
            ->pluck('paid', 'deal_id');

        // Подтверждённые расходы РАЗДЕЛЬНО: закуп со склада (material_id),
        // доставка, закуп (тип из формы расхода) и прочие — свои колонки.
        $expByDeal = Expense::where('status', 'confirmed')->where('expenseable_type', 'deal')
            ->whereIn('expenseable_id', $deals->pluck('id'))
            ->groupBy('expenseable_id')
            ->selectRaw("expenseable_id as deal_id,
                sum(case when material_id is not null then amount else 0 end) as material,
                sum(case when material_id is null and type = 'delivery' then amount else 0 end) as delivery,
                sum(case when material_id is null and type in ('purchase','metal','sheet','fittings') then amount else 0 end) as purchase,
                sum(case when material_id is null and type = 'assembly' then amount else 0 end) as assembly,
                sum(case when material_id is null and (type is null or type not in ('delivery','purchase','assembly','metal','sheet','fittings')) then amount else 0 end) as other")
            ->get()->keyBy('deal_id');

        // Активный заказ цеха по сделке: этап цеха показывается прямо в общей
        // таблице (вторым бейджем в колонке «Этап») — цех и сделки вместе.
        $workshopByDeal = \App\Models\Project::query()
            ->with('stage:id,name,color')
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereIn('deal_id', $deals->pluck('id'))
            ->get()->keyBy('deal_id');

        // Порядок этапа «ЭСФ» каждой воронки — для флага is_post_esf (снятие просрочки).
        $esfOrders = \App\Models\DealStage::where('stage_type', 'esf')->pluck('order', 'company_id');

        $rows = $deals->map(function ($d) use ($paidByDeal, $expByDeal, $workshopByDeal, $taxRate, $esfOrders) {
            $budget = (float) $d->budget;
            $material = (float) ($expByDeal[$d->id]->material ?? 0);
            $delivery = (float) ($expByDeal[$d->id]->delivery ?? 0);
            $purchase = (float) ($expByDeal[$d->id]->purchase ?? 0);
            $assembly = (float) ($expByDeal[$d->id]->assembly ?? 0);
            $other = (float) ($expByDeal[$d->id]->other ?? 0);
            $expense = $material + $delivery + $purchase + $assembly + $other;
            $tax = round($budget * $taxRate, 2);
            $partner = PayrollService::partnerSum($budget, $d->partner_pct);
            $remainder = round($budget - $tax - $expense - $partner, 2);
            // Та же формула бонуса, что на карточке сделки и в ЗП (с ручным % финансиста).
            $override = $d->bonus_rate_override !== null ? (float) $d->bonus_rate_override : null;
            $bonus = PayrollService::marginBonus($budget, $remainder, $tax, $override);
            $company = round($remainder - $bonus, 2);
            // Ставка бонуса (авто-ступень от коммерческой маржи или ручная финансиста) — для чипа у суммы.
            $bonusRate = round(PayrollService::effectiveBonusRate(PayrollService::marginPct($budget, $remainder, $tax), $override) * 100, 1);

            return [
                'id' => $d->id,
                'number' => $d->number,
                'bin' => $d->bin,
                'company_name' => $d->company_name,
                'address' => $d->address,
                'product' => $d->client_name, // «Наименование товара» (историческое имя колонки)
                'qty' => trim(($d->lot_number ?? '').' '.($d->unit ?? '')),
                'budget' => $budget,
                'paid' => (float) ($paidByDeal[$d->id] ?? 0),
                'material' => $material,
                'delivery' => $delivery,
                'purchase' => $purchase,
                'assembly' => $assembly,
                'other' => $other,
                'partner' => $partner,
                'partner_pct' => $d->partner_pct !== null ? (float) $d->partner_pct : null,
                'tax' => $tax,
                'remainder' => $remainder,
                // Маржа = остаток/сумма — РОВНО та, по которой выбрана ступень
                // бонуса (правило от 10.09.2026: раньше показывали маржу «после
                // бонуса», и 21.6% → ставка 10% выглядели как 19.4% → 10%).
                'margin' => PayrollService::marginPct($budget, $remainder),
                'bonus' => $bonus,
                'bonus_rate' => $bonusRate,
                'bonus_manual' => $override !== null,
                'company' => $company,
                'manager' => $d->responsible?->name,
                'manager_id' => $d->responsible_user_id,
                'deadline' => optional($d->deadline)->toDateString(),
                'stage' => $d->stage?->name,
                'stage_color' => $d->stage?->color,
                'workshop_stage' => $workshopByDeal->get($d->id)?->stage?->name,
                'workshop_color' => $workshopByDeal->get($d->id)?->stage?->color,
                'workshop_number' => $workshopByDeal->get($d->id)?->number,
                'is_won' => (bool) $d->stage?->is_won,
                // Группы подсветки по stage_type (имя этапа ненадёжно):
                // Акт/ЭСФ — зелёные как won; Логистика/Сборка — жёлтые.
                'is_pending_won' => in_array($d->stage?->stage_type, ['act', 'esf'], true),
                'is_esf' => $d->stage?->stage_type === 'esf',
                // С ЭСФ и дальше (Оплата, Тендер закрыт) просрочка снимается.
                'is_post_esf' => ($eo = $esfOrders[$d->company_id] ?? null) !== null
                    && $d->stage && $d->stage->order >= $eo,
                'is_logistics' => in_array($d->stage?->stage_type, ['logistics', 'assembly'], true),
            ];
        })->values();

        $budgetSum = $rows->sum('budget');
        $companySum = $rows->sum('company');
        $totals = [
            'budget' => $budgetSum,
            'paid' => $rows->sum('paid'),
            'material' => $rows->sum('material'),
            'delivery' => $rows->sum('delivery'),
            'purchase' => $rows->sum('purchase'),
            'assembly' => $rows->sum('assembly'),
            'other' => $rows->sum('other'),
            'partner' => $rows->sum('partner'),
            'tax' => $rows->sum('tax'),
            'remainder' => $rows->sum('remainder'),
            'bonus' => $rows->sum('bonus'),
            'company' => $companySum,
            'margin' => $budgetSum > 0 ? round($rows->sum('remainder') / $budgetSum * 100, 1) : 0,
            'count' => $rows->count(),
        ];

        // Сводная по МОП: строка = менеджер (а не сделка) с общим оборотом за
        // выбранный период. Считается из тех же $rows, что и таблица ниже, —
        // итоги двух блоков сходятся по определению.
        $byManager = $rows->groupBy(fn ($r) => $r['manager_id'] ?? 0)
            ->map(function ($list) {
                $budget = (float) $list->sum('budget');
                $company = (float) $list->sum('company');

                return [
                    'manager_id' => $list->first()['manager_id'],
                    'manager' => $list->first()['manager'] ?? 'Без менеджера',
                    'deals' => $list->count(),
                    'won' => $list->where('is_won', true)->count(),
                    'budget' => $budget,
                    'paid' => (float) $list->sum('paid'),
                    'expense' => (float) $list->sum(fn ($r) => $r['material'] + $r['delivery'] + $r['purchase'] + $r['assembly'] + $r['other']),
                    'tax' => (float) $list->sum('tax'),
                    'remainder' => (float) $list->sum('remainder'),
                    'bonus' => (float) $list->sum('bonus'),
                    'company' => $company,
                    // Маржа менеджера — доход фирмы к его обороту, а не среднее по сделкам.
                    'margin' => $budget > 0 ? round((float) $list->sum('remainder') / $budget * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('budget')->values();

        // «На каком этапе стоят сделки»: сколько штук и на какую сумму висит на
        // каждом этапе воронки. Порядок — как в воронке, а не по алфавиту.
        $stageOrder = \App\Models\DealStage::pluck('order', 'id');
        $byStage = $deals->groupBy('deal_stage_id')
            ->map(function ($list, $stageId) use ($rows, $stageOrder) {
                $ids = $list->pluck('id');
                $stageRows = $rows->whereIn('id', $ids);
                $first = $list->first();

                return [
                    'stage_id' => $stageId ?: null,
                    'stage' => $first->stage?->name ?? 'Без этапа',
                    'color' => $first->stage?->color,
                    'is_won' => (bool) $first->stage?->is_won,
                    'count' => $list->count(),
                    'budget' => (float) $stageRows->sum('budget'),
                    'paid' => (float) $stageRows->sum('paid'),
                    'order' => (int) ($stageOrder[$stageId] ?? 999),
                ];
            })
            ->sortBy('order')->values();

        // Опции фильтров: активные менеджеры и этапы воронки текущей компании
        // (в режиме «Все компании» — обе воронки с пометкой фирмы).
        $companyId = \App\Support\CurrentCompany::id() ?: null;
        $companyNames = \App\Models\Company::pluck('name', 'id');
        $stageOptions = \App\Models\DealStage::with('translations')->where('is_active', true)
            ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w->where('company_id', $c)->orWhereNull('company_id')))
            ->orderBy('order')->get()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName().(! $companyId && $s->company_id ? ' · '.($companyNames[$s->company_id] ?? '') : '')])
            ->values();

        // Прибыль фирмы и маржа — только руководству. Прячем не в шаблоне, а в
        // данных: иначе цифры видны в исходнике страницы (props Inertia).
        if (! $isLeadership) {
            $hideProfit = fn ($r) => \Illuminate\Support\Arr::except($r, ['company', 'margin']);
            $rows = $rows->map($hideProfit);
            $byManager = $byManager->map($hideProfit);
            $totals = $hideProfit($totals);
        }

        return [
            'rows' => $rows,
            'byManager' => $byManager,
            'byStage' => $byStage,
            'isLeadership' => $isLeadership,
            'totals' => $totals,
            'taxRate' => $taxRate * 100,
            'filters' => ['search' => $search, 'from' => $from, 'to' => $to, 'manager' => $managerId, 'stage' => $stageId, 'source' => $source],
            'sources' => Deal::SOURCES,
            'canPlanFact' => $user->hasAnyRole(['admin', 'director']),
            // Для фильтра: менеджеры отдельно, остальные — по отделам (сворачиваются).
            // МОПу выбирать не из кого — отчёт и так только по его сделкам.
            'managers' => \App\Models\User::where('is_active', true)->ofCompany($companyId)
                ->when(! $isLeadership, fn ($q) => $q->whereKey($user->id))
                ->with(['roles:id,name', 'department:id,name'])
                ->orderBy('name')->get(['id', 'name', 'department_id'])
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'is_manager' => $u->roles->contains('name', 'manager'),
                    'department' => $u->department?->name,
                ])->values(),
            'stageOptions' => $stageOptions,
        ];
    }
}
