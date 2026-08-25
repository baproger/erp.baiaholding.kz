<?php

namespace App\Http\Controllers;

use App\Models\PayrollAdjustment;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkHour;
use App\Services\PayrollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PayrollController extends Controller
{
    /** Корректировки и оклад вводит только бухгалтер (financist) или админ. */
    private function canManage(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'financist']);
    }

    public function index(Request $request, PayrollService $payroll, \App\Services\EmployeeDebtService $debts): Response
    {
        return Inertia::render('Payroll/Index', $this->sheet($request, $payroll, $debts));
    }

    /**
     * Страница «Бонусы» — ГОДОВАЯ таблица накоплений (правило от 21.08.2026):
     * строки — менеджеры, 12 колонок-месяцев выбранного года (бонус заработан /
     * выплачен), справа — за год, выплачено, и главное — «К выплате»:
     * накопленный баланс за ВСЁ время (менеджеры копят бонусы месяцами).
     * К выплате = Σ бонусов (всё время) − Σ выплат из бонуса (авансы «из
     * бонуса» + погашения долгов).
     */
    /**
     * Разбивка «К выплате» на странице Бонусы (клик по «переносу» или сумме):
     * из каких выигранных сделок сложился бонус ЗА ВСЁ ВРЕМЯ, какие сделки
     * ещё не оплачены клиентом (бонус ждёт оплаты), и какие выплаты/удержания
     * были. Итог совпадает с колонкой «К выплате».
     * Доступ: руководство — по любому сотруднику, менеджер — только по себе.
     */
    public function bonusCarry(Request $request, PayrollService $payroll)
    {
        $viewer = $request->user();
        abort_unless($viewer->can('payroll.view'), 403);
        $leadership = $viewer->hasAnyRole(['admin', 'director', 'financist']);

        $uid = (int) $request->integer('user');
        if (! $leadership) {
            $uid = $viewer->id;
        }

        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;
        $deals = \App\Models\Deal::won()->forCurrentCompany()
            ->where('responsible_user_id', $uid)
            ->get(['id', 'number', 'company_name', 'budget', 'partner_pct', 'bonus_rate_override', 'contract_date', 'created_at']);

        $ids = $deals->pluck('id');
        $paidByDeal = \App\Models\Payment::query()
            ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->where('invoices.invoiceable_type', 'deal')
            ->whereIn('invoices.invoiceable_id', $ids)
            ->groupBy('invoices.invoiceable_id')
            ->selectRaw('invoices.invoiceable_id as did, SUM(payments.amount) as v')->pluck('v', 'did');
        $expenseByDeal = \App\Models\Expense::where('status', 'confirmed')->where('expenseable_type', 'deal')
            ->whereIn('expenseable_id', $ids)
            ->groupBy('expenseable_id')->selectRaw('expenseable_id as did, SUM(amount) as v')->pluck('v', 'did');

        $earned = collect();   // бонус уже начислен (клиент оплатил)
        $pending = collect();  // бонус ждёт оплаты клиента
        foreach ($deals as $d) {
            $budget = (float) $d->budget;
            $expense = (float) ($expenseByDeal[$d->id] ?? 0);
            $tax = round($budget * $taxRate, 2);
            $remainder = round($budget - $tax - $expense - PayrollService::partnerSum($budget, $d->partner_pct), 2);
            $clientPaid = (float) ($paidByDeal[$d->id] ?? 0);
            $ratio = $budget > 0 ? min(1, $clientPaid / $budget) : 0;
            $fullBonus = PayrollService::marginBonus($budget, $remainder, $tax,
                $d->bonus_rate_override !== null ? (float) $d->bonus_rate_override : null);
            $bonus = round($fullBonus * $ratio, 2);
            $waiting = round($fullBonus - $bonus, 2);
            $row = [
                'id' => $d->id,
                'number' => $d->number,
                'customer' => $d->company_name,
                'date' => $d->contract_date?->toDateString() ?? $d->created_at->toDateString(),
                'bonus' => $bonus,
            ];
            if ($bonus != 0) {
                $earned->push($row);
            }
            // Клиент оплатил не всё — остаток бонуса «заморожен» до оплаты.
            if ($waiting > 0.009) {
                $pending->push($row + [
                    'waiting' => $waiting,
                    'budget' => $budget,
                    'client_paid' => round($clientPaid, 2),
                ]);
            }
        }
        $earned = $earned->sortByDesc('date')->values();
        $pending = $pending->sortByDesc('waiting')->values();

        // Выплаты за всё время: авансы/выплаты «из бонуса» + погашения долгов.
        $typeRu = ['advance' => 'Аванс из бонуса', 'payout' => 'Выплата бонуса'];
        $payoutRows = PayrollAdjustment::whereIn('type', ['advance', 'payout'])->where('source', 'bonus')
            ->where('user_id', $uid)
            ->orderByDesc('date')->get(['type', 'amount', 'date', 'note'])
            ->map(fn ($a) => [
                'label' => $typeRu[$a->type] ?? $a->type,
                'date' => $a->date->toDateString(),
                'amount' => (float) $a->amount,
                'note' => $a->note,
            ]);
        $debtRows = \App\Models\EmployeeDebtPayment::query()
            ->join('employee_debts', 'employee_debts.id', '=', 'employee_debt_payments.employee_debt_id')
            ->where('employee_debts.user_id', $uid)
            ->orderByDesc('employee_debt_payments.month')
            ->get(['employee_debt_payments.amount', 'employee_debt_payments.month', 'employee_debts.description'])
            ->map(fn ($d) => [
                'label' => 'Погашение долга из бонуса',
                'date' => $d->month.'-01',
                'amount' => (float) $d->amount,
                'note' => $d->description,
            ]);
        $paidRows = $payoutRows->concat($debtRows)->sortByDesc('date')->values();

        $earnedSum = round($earned->sum('bonus'), 2);
        $paidSum = round($paidRows->sum('amount'), 2);

        return response()->json([
            'earned' => $earned,
            'pending' => $pending,
            'pending_sum' => round($pending->sum('waiting'), 2),
            'paid' => $paidRows,
            'earned_sum' => $earnedSum,
            'paid_sum' => $paidSum,
            'balance' => round($earnedSum - $paidSum, 2),
        ]);
    }

    public function bonuses(Request $request, PayrollService $payroll): Response
    {
        $user = $request->user();
        abort_unless($user->can('payroll.view'), 403);
        $leadership = $user->hasAnyRole(['admin', 'director', 'financist']);
        $year = (int) ($request->integer('year') ?: now()->year);
        $year = max(2020, min($year, (int) now()->year + 1));

        // Бонус по месяцам года (12 срезов) и за всё время.
        $byMonth = [];
        for ($m = 1; $m <= 12; $m++) {
            $byMonth[$m] = $payroll->bonusByUserForMonth(sprintf('%d-%02d', $year, $m));
        }
        $allTime = $payroll->perUser(false)->keyBy('uid')->map(fn ($r) => (float) $r['bonus']);
        $earnedBefore = $payroll->bonusByUserBefore($year.'-01-01');

        // Выплаты из бонуса: авансы «из бонуса» + погашения долгов — по месяцам и за всё время.
        $advAll = PayrollAdjustment::whereIn('type', ['advance', 'payout'])->where('source', 'bonus')
            ->get(['user_id', 'amount', 'date']);
        $debtAll = \App\Models\EmployeeDebtPayment::query()
            ->join('employee_debts', 'employee_debts.id', '=', 'employee_debt_payments.employee_debt_id')
            ->get(['employee_debts.user_id', 'employee_debt_payments.amount', 'employee_debt_payments.month']);
        $paidMonth = [];   // uid => [m => sum]
        $paidAllBy = [];   // uid => sum
        $paidBefore = [];  // uid => выплачено ДО 1 января выбранного года
        foreach ($advAll as $a) {
            $paidAllBy[$a->user_id] = ($paidAllBy[$a->user_id] ?? 0) + (float) $a->amount;
            if ((int) $a->date->year < $year) {
                $paidBefore[$a->user_id] = ($paidBefore[$a->user_id] ?? 0) + (float) $a->amount;
            }
            if ((int) $a->date->year === $year) {
                $paidMonth[$a->user_id][(int) $a->date->month] = ($paidMonth[$a->user_id][(int) $a->date->month] ?? 0) + (float) $a->amount;
            }
        }
        foreach ($debtAll as $d) {
            $paidAllBy[$d->user_id] = ($paidAllBy[$d->user_id] ?? 0) + (float) $d->amount;
            if ((int) substr((string) $d->month, 0, 4) < $year) {
                $paidBefore[$d->user_id] = ($paidBefore[$d->user_id] ?? 0) + (float) $d->amount;
            }
            if (str_starts_with((string) $d->month, $year.'-')) {
                $mm = (int) substr($d->month, 5, 2);
                $paidMonth[$d->user_id][$mm] = ($paidMonth[$d->user_id][$mm] ?? 0) + (float) $d->amount;
            }
        }

        // Кто в таблице: все, у кого есть бонус/выплаты; менеджер видит только себя.
        $uids = collect(array_keys($paidAllBy))->merge($allTime->keys());
        foreach ($byMonth as $col) {
            $uids = $uids->merge($col->keys());
        }
        $uids = $uids->unique()->filter()->values();
        if (! $leadership) {
            $uids = $uids->filter(fn ($id) => (int) $id === (int) $user->id)->values();
        }
        $people = User::whereIn('id', $uids)->get(['id', 'name', 'avatar'])->keyBy('id');

        $rows = $uids->filter(fn ($id) => $people->has($id))->map(function ($uid) use ($byMonth, $paidMonth, $allTime, $paidAllBy, $people, $earnedBefore, $paidBefore) {
            $months = [];
            $yearEarned = 0.0;
            $yearPaid = 0.0;
            for ($m = 1; $m <= 12; $m++) {
                $b = round((float) ($byMonth[$m][$uid] ?? 0), 2);
                $p = round((float) ($paidMonth[$uid][$m] ?? 0), 2);
                $months[] = ['m' => $m, 'bonus' => $b, 'paid' => $p];
                $yearEarned += $b;
                $yearPaid += $p;
            }
            $earnedAll = round((float) ($allTime[$uid] ?? 0), 2);
            $paidAll = round((float) ($paidAllBy[$uid] ?? 0), 2);

            return [
                'uid' => $uid,
                'user' => $people[$uid]->name,
                'avatar' => $people[$uid]->avatar,
                'months' => $months,
                'year_earned' => round($yearEarned, 2),
                'year_paid' => round($yearPaid, 2),
                'earned_all' => $earnedAll,
                'paid_all' => $paidAll,
                // Перенос с прошлых лет: строго ДО 1 января выбранного года
                // (раньше сюда ошибочно попадали и будущие годы).
                'carry' => round((float) ($earnedBefore[$uid] ?? 0) - (float) ($paidBefore[$uid] ?? 0), 2),
                'balance' => round($earnedAll - $paidAll, 2),
            ];
        })->sortByDesc('balance')->values();

        return Inertia::render('Payroll/Bonuses', [
            'rows' => $rows,
            'year' => $year,
            'leadership' => $leadership,
            'canManage' => $this->canManage($request),
            'totals' => [
                'year_earned' => (float) $rows->sum('year_earned'),
                'year_paid' => (float) $rows->sum('year_paid'),
                'balance' => (float) $rows->sum('balance'),
            ],
        ]);
    }

    /** Общий расчёт ведомости для страниц «Зарплата» и «Бонусы». @return array<string, mixed> */
    private function sheet(Request $request, PayrollService $payroll, \App\Services\EmployeeDebtService $debts): array
    {
        $user = $request->user();
        abort_unless($user->can('payroll.view'), 403);

        $leadership = $user->hasAnyRole(['admin', 'director', 'financist']);
        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;

        // Месяц корректировок (отгулы/больничные/штрафы/премии): YYYY-MM.
        $month = preg_match('/^\d{4}-\d{2}$/', $request->string('month')->toString())
            ? $request->string('month')->toString() : now()->format('Y-m');
        $monthStart = $month.'-01';
        $monthEnd = \Illuminate\Support\Carbon::parse($monthStart)->endOfMonth()->toDateString();

        $adjustments = PayrollAdjustment::with('creator:id,name')
            ->whereDate('date', '>=', $monthStart)->whereDate('date', '<=', $monthEnd)
            ->orderBy('date')->get()->groupBy('user_id');

        // Почасовой расчёт (Excel владельца): ставка/час = оклад ÷ норма часов месяца,
        // начислено = часы × ставка. Норма — одна на месяц для всех (Setting),
        // fallback — последняя использованная норма (work_norm_default).
        $normHours = (float) Setting::get('work_norm_'.$month, Setting::get('work_norm_default', 176));
        $hoursByUser = WorkHour::where('month', $month)->get()->keyBy('user_id');

        // Single source of truth for the payroll math (shared with Analytics & Finance).
        $rows = $payroll->perUser(true)->sortByDesc('bonus')->values();
        if (! $leadership) {
            $rows = $rows->filter(fn ($r) => $r['uid'] === $user->id)->values();
        }

        // Per-deal breakdown so a row can expand into the employee's «Оплата успешно»
        // and «Акт утверждение» deals — the raw data the financist needs to check ЗП.
        $breakdown = $payroll->dealBreakdown();
        // Отдел и фирмы сотрудника — ведомость режется секциями «компания → отдел».
        $deptByUser = User::whereIn('id', $rows->pluck('uid'))
            ->with(['department:id,name,code,company_id', 'companies:companies.id,name'])
            ->get(['id', 'department_id'])->keyBy('id');
        // Своя норма часов у отдела (цех 200 ч, менеджеры 220 ч…): work_norm_{месяц}:dept:{id};
        // нет своей — действует общая норма месяца.
        $deptNorms = $deptByUser->pluck('department_id')->filter()->unique()
            ->mapWithKeys(fn ($id) => [$id => Setting::get('work_norm_'.$month.':dept:'.$id)])
            ->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);
        // Долги сотрудников: план погашения за ВЫБРАННЫЙ месяц. Считается из
        // бонуса именно этого месяца (bonusByUserForMonth), а не из общего —
        // иначе долг гасился бы бонусами, которых в месяце не было.
        $bonusOfMonth = $payroll->bonusByUserForMonth($month);
        $debtPlans = [];
        $debtList = [];
        // Долги месяца = открытые + закрывшиеся этим месяцем: иначе полностью
        // погашенный долг исчез бы из ведомости вместе со своим удержанием,
        // и «К выплате» подскочило бы после прогона cron.
        // Грузим на ВСЕХ сразу (2 запроса), а не по сотруднику в цикле.
        foreach ($debts->forMonthMany($rows->pluck('uid'), $month) as $uid => $ofMonth) {
            $debtPlans[$uid] = $debts->planFrom($ofMonth, $month, (float) ($bonusOfMonth[$uid] ?? 0));
            $debtList[$uid] = $ofMonth->map(fn ($d) => [
                'id' => $d->id,
                'amount' => (float) $d->amount,
                'monthly_amount' => (float) $d->monthly_amount,
                'paid' => $d->paidSum(),
                'remaining' => $d->remaining(),
                'paid_this_month' => (float) ($d->payments->firstWhere('month', $month)?->amount ?? 0),
                'closed' => $d->closed_at !== null,
                'date' => optional($d->date)->toDateString(),
                'note' => $d->note,
            ])->values()->all();
        }

        $rows = $rows->map(function ($r) use ($breakdown, $adjustments, $hoursByUser, $normHours, $deptByUser, $deptNorms, $debtPlans, $debtList, $bonusOfMonth) {
            $r['dealsList'] = array_values(($breakdown->get($r['uid']) ?? collect())->all());
            // Бонус ЗА ВЫБРАННЫЙ МЕСЯЦ — информационный срез рядом с общим
            // «за всё время». В «К выплате» НЕ участвует: формула выплаты
            // осознанно считается от общего бонуса (решение владельца).
            // Переиспользуем уже посчитанный для долгов $bonusOfMonth.
            $r['bonus_month'] = (float) ($bonusOfMonth[$r['uid']] ?? 0);
            $adj = $adjustments->get($r['uid']) ?? collect();
            // Аванс с ИСТОЧНИКОМ: «из ЗП» уменьшает итог зарплаты, «из бонуса» —
            // итог бонуса (правило от 20.08.2026). Штрафы/отгулы — всегда из ЗП.
            // «Выплата» (payout) — прямая выдача денег по ведомости, считается как аванс своего источника.
            $advSalary = round((float) $adj->whereIn('type', ['advance', 'payout'])->where('source', '!=', 'bonus')->sum('amount'), 2);
            $advBonus = round((float) $adj->whereIn('type', ['advance', 'payout'])->where('source', 'bonus')->sum('amount'), 2);
            $penalties = round((float) $adj->whereIn('type', ['absence', 'sick', 'fine'])->sum('amount'), 2);
            $deductions = round($penalties + $advSalary, 2);
            $additions = round((float) $adj->where('type', 'bonus')->sum('amount'), 2);
            // Командировочные — начисление сверх оклада (колонка из Excel владельца).
            $trip = round((float) $adj->where('type', 'trip')->sum('amount'), 2);
            $r['adjustments'] = $adj->map(fn ($a) => [
                'id' => $a->id, 'type' => $a->type, 'source' => $a->source ?? 'salary',
                'days' => $a->days !== null ? (float) $a->days : null,
                'amount' => (float) $a->amount, 'date' => optional($a->date)->toDateString(),
                'created_at' => optional($a->created_at)->toIso8601String(),
                'note' => $a->note, 'creator' => $a->creator?->name,
            ])->values();
            $r['deductions'] = $deductions;
            $r['additions'] = $additions;
            $r['penalties'] = $penalties;
            $r['adv_salary'] = $advSalary;
            $r['adv_bonus'] = $advBonus;
            $r['trip'] = $trip;

            // Почасовая база: день + ночь (ночной час = дневная ставка × 1.5);
            // часы не введены — полный оклад, как раньше.
            $deptId = $deptByUser[$r['uid']]?->department_id;
            $norm = (float) ($deptNorms[$deptId] ?? $normHours);
            $wh = $hoursByUser[$r['uid']] ?? null;
            $hours = $wh && $wh->hours !== null ? (float) $wh->hours : null;
            $night = $wh && $wh->night_hours !== null ? (float) $wh->night_hours : null;
            $rate = $norm > 0 ? $r['salary'] / $norm : 0.0;
            $r['hours'] = $hours;
            $r['night_hours'] = $night;
            $r['hourly_rate'] = $norm > 0 ? round($rate, 2) : null;
            // День и ночь раздельно (колонки Excel владельца): ночь = ставка × 1.5.
            $byHours = ($hours !== null || $night !== null) && $norm > 0;
            $r['base_day'] = $byHours ? round(($hours ?? 0) * $rate, 2) : $r['salary'];
            $r['base_night'] = $byHours ? round(($night ?? 0) * $rate * 1.5, 2) : 0.0;
            $r['base'] = round($r['base_day'] + $r['base_night'], 2);
            // ВСЕГО = день + ночь + премии + командировочные.
            $r['gross'] = round($r['base'] + $additions + $trip, 2);
            // Раздельные итоги: ЗП = ВСЕГО − штрафы − аванс(ЗП);
            // бонус месяца − аванс(бонус) — долг удерживается ниже тоже из бонуса.
            $r['salary_final'] = round($r['gross'] - $penalties - $advSalary, 2);
            $r['bonus_final'] = round($r['bonus_month'] - $advBonus, 2);
            // Легаси-поля (Финансы/Аналитика смотрят на общий свод) — не ломаем.
            $r['payout'] = round($r['base'] + $r['bonus'], 2);
            $r['final'] = round($r['payout'] - $deductions - $advBonus + $additions, 2);
            // Долг: удержание ТОЛЬКО из бонуса месяца и не больше месячного
            // платежа. Оклад не трогаем — нет бонуса, нет и удержания.
            $plan = $debtPlans[$r['uid']] ?? null;
            $r['debt_charge'] = $plan['charge'] ?? 0.0;
            $r['debt_planned'] = $plan['planned'] ?? 0.0;
            $r['debt_remaining'] = $plan['before'] ?? 0.0;
            $r['debt_after'] = $plan['after'] ?? 0.0;
            $r['debts'] = $debtList[$r['uid']] ?? [];
            $r['final'] = round($r['final'] - $r['debt_charge'], 2);
            $r['bonus_final'] = round($r['bonus_final'] - $r['debt_charge'], 2);
            $r['department'] = $deptByUser[$r['uid']]?->department?->name;
            $r['department_id'] = $deptId;
            // code — общий ключ одноимённых отделов BAIA/ASU: сотрудник обеих
            // фирм встаёт в «свой» отдел в секции каждой из них.
            $r['department_code'] = $deptByUser[$r['uid']]?->department?->code;
            $companyIds = ($deptByUser[$r['uid']]?->companies ?? collect())->pluck('id')->sort()->values();
            $r['company_ids'] = $companyIds->all();
            // Работающий в обеих фирмах виден в обеих секциях, но его деньги
            // попадают в итог ТОЛЬКО основной фирмы — иначе холдинг заплатил бы дважды.
            $r['primary_company_id'] = $companyIds->first();

            return $r;
        });

        // Переключатель фирмы (шапка): выбрана конкретная компания — ведомость,
        // секции и плитки сужаются до неё; «Все» — обе фирмы, как раньше.
        // Сотрудник без фирмы виден везде (иначе новая карточка потеряется).
        $currentCompany = \App\Support\CurrentCompany::id() ?: null;
        if ($currentCompany) {
            $rows = $rows->filter(fn ($r) => $r['company_ids'] === []
                || in_array($currentCompany, $r['company_ids'], true))->values();
        }
        // В плитки деньги «двойного» сотрудника входят только по его основной
        // фирме — то же правило, что в итогах секций (нет двойного счёта).
        $counted = $currentCompany
            ? $rows->filter(fn ($r) => $r['primary_company_id'] === null
                || (int) $r['primary_company_id'] === $currentCompany)
            : $rows;

        return [
            'rows' => $rows,
            'leadership' => $leadership,
            'canManage' => $this->canManage($request),
            'month' => $month,
            'normHours' => $normHours,
            'deptNorms' => $deptNorms,
            'taxRate' => $taxRate * 100,
            'companies' => \App\Models\Company::where('is_active', true)
                ->when($currentCompany, fn ($q, $c) => $q->where('id', $c))
                ->orderBy('id')->get(['id', 'name']),
            // Отделы всех фирм: по ним ведомость раскладывает сотрудника
            // в отдел ЕГО фирмы (у «двойного» department_id указывает на одну).
            'departments' => \App\Models\Department::where('is_active', true)
                ->orderBy('company_id')->orderBy('name')->get(['id', 'company_id', 'name', 'code']),
            'totals' => [
                'budget' => (float) $counted->sum('budget'),
                'tax' => (float) $counted->sum('tax'),
                'expense' => (float) $counted->sum('expense'),
                'bonus' => (float) $counted->sum('bonus'),
                'bonus_month' => (float) $counted->sum('bonus_month'),
                'salary' => (float) $counted->sum('salary'),
                'base' => (float) $counted->sum('base'),
                'payout' => (float) $counted->sum('payout'),
                'deductions' => (float) $counted->sum('deductions'),
                'additions' => (float) $counted->sum('additions'),
                'debt_charge' => (float) $counted->sum('debt_charge'),
                'debt_remaining' => (float) $counted->sum('debt_remaining'),
                'final' => (float) $counted->sum('final'),
                'company' => (float) $counted->sum('company'),
                'penalties' => (float) $counted->sum('penalties'),
                'trip' => (float) $counted->sum('trip'),
                'gross' => (float) $counted->sum('gross'),
                'base_day' => (float) $counted->sum('base_day'),
                'base_night' => (float) $counted->sum('base_night'),
                'adv_salary' => (float) $counted->sum('adv_salary'),
                'adv_bonus' => (float) $counted->sum('adv_bonus'),
                'salary_final' => (float) $counted->sum('salary_final'),
                'bonus_final' => (float) $counted->sum('bonus_final'),
            ],
        ];
    }

    /**
     * Корректировка ЗП: отгул/больничный — можно днями (сумма = оклад/22 × дни),
     * штраф/премия — суммой. Только бухгалтер/админ.
     */
    public function storeAdjustment(Request $request): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Корректировки вводит бухгалтер или админ.');

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'type' => ['required', Rule::in(PayrollAdjustment::TYPES)],
            'days' => ['nullable', 'numeric', 'min:0.5', 'max:31'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            // Для аванса: откуда выданы деньги (нал/банк) — уйдёт в Расходы.
            'payment_method' => ['nullable', Rule::in(['cash', 'bank'])],
            // Источник аванса: из ЗП (умолчание) или из бонуса.
            'source' => ['nullable', Rule::in(['salary', 'bonus'])],
        ]);
        $data['source'] = $data['source'] ?? 'salary';

        // Автосумма для отгула/больничного: оклад / 22 рабочих дня × дни.
        if (empty($data['amount']) && ! empty($data['days']) && in_array($data['type'], ['absence', 'sick'], true)) {
            $salary = (float) (User::find($data['user_id'])->salary ?? 0);
            $data['amount'] = round($salary / 22 * (float) $data['days'], 2);
        }
        if (empty($data['amount']) || (float) $data['amount'] <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'amount' => 'Укажите сумму (или дни — для отгула/больничного при заполненном окладе).',
            ]);
        }

        $data['created_by'] = $request->user()->id;

        // АВАНС — реальные деньги из кассы/банка: фиксируем и в Финансах —
        // подтверждённый расход компании, категория «Расходы по сотрудникам»
        // (не «прочие»). Удаление корректировки удалит и расход.
        if (in_array($data['type'], ['advance', 'payout'], true)) {
            $employee = User::find($data['user_id']);
            $category = \App\Models\ExpenseCategory::firstOrCreate(
                ['name' => \App\Models\ExpenseCategory::EMPLOYEE],
                ['is_active' => true]
            );
            $expense = \App\Models\Expense::create([
                'company_id' => \App\Support\CurrentCompany::id()
                    ?: $employee->companies()->value('companies.id'),
                'category_id' => $category->id,
                'type' => 'direct',
                'amount' => $data['amount'],
                'date' => $data['date'],
                'description' => ($data['type'] === 'payout'
                        ? ($data['source'] === 'bonus' ? 'Выплата бонуса: ' : 'Выплата ЗП: ')
                        : 'Аванс сотруднику: ').$employee->name
                    .(! empty($data['note']) ? ' — '.$data['note'] : ''),
                'responsible_user_id' => $employee->id,
                // Явная связь «кому выдали»: responsible_user_id у расхода по
                // сделке значит «кто потратил», поэтому смыслы разведены.
                'employee_id' => $employee->id,
                'employee_payout' => $data['type'] === 'payout' ? ($data['source'] === 'bonus' ? 'bonus_payout' : 'salary_payout') : 'advance',
                'status' => 'confirmed',
                'payment_method' => $data['payment_method'] ?? 'cash',
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now(),
            ]);
            $data['expense_id'] = $expense->id;
            $data['payment_method'] = $data['payment_method'] ?? 'cash';
        }

        PayrollAdjustment::create($data);

        return back()->with('success', match ($data['type']) {
            'advance' => 'Аванс добавлен и зафиксирован в Расходах на Финансах.',
            'payout' => ($data['source'] === 'bonus' ? 'Бонус выплачен' : 'ЗП выплачена').' — зафиксировано в Расходах на Финансах.',
            default => 'Корректировка добавлена.',
        });
    }

    /**
     * Выдать долг сотруднику. Деньги реальные: как и аванс, создаёт
     * подтверждённый расход компании и уменьшает кассу/банк. Дальше долг
     * гасится сам — фиксированной суммой в месяц и ТОЛЬКО из бонуса.
     */
    public function storeDebt(Request $request): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Долги заводит бухгалтер или админ.');

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'monthly_amount' => ['required', 'numeric', 'min:1'],
            'date' => ['required', 'date'],
            'payment_method' => ['required', Rule::in(['cash', 'bank'])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if ((float) $data['monthly_amount'] > (float) $data['amount']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'monthly_amount' => 'Ежемесячный платёж не может быть больше самого долга.',
            ]);
        }

        $employee = User::findOrFail($data['user_id']);
        $companyId = \App\Support\CurrentCompany::id() ?: $employee->companies()->value('companies.id');

        $category = \App\Models\ExpenseCategory::firstOrCreate(
            ['name' => \App\Models\ExpenseCategory::EMPLOYEE],
            ['is_active' => true]
        );
        $expense = \App\Models\Expense::create([
            'company_id' => $companyId,
            'category_id' => $category->id,
            'type' => 'direct',
            'amount' => $data['amount'],
            'date' => $data['date'],
            'description' => 'Долг сотруднику: '.$employee->name
                .(! empty($data['note']) ? ' — '.$data['note'] : ''),
            'responsible_user_id' => $employee->id,
            'employee_id' => $employee->id,
            'employee_payout' => 'debt',
            'status' => 'confirmed',
            'payment_method' => $data['payment_method'],
            'confirmed_by' => $request->user()->id,
            'confirmed_at' => now(),
        ]);

        \App\Models\EmployeeDebt::create($data + [
            'company_id' => $companyId,
            'expense_id' => $expense->id,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Долг выдан и зафиксирован в Расходах на Финансах.');
    }

    /** Удалить долг: вместе с ним уходит расход (деньги вернулись в кассу). */
    public function destroyDebt(Request $request, \App\Models\EmployeeDebt $debt): RedirectResponse
    {
        abort_unless($this->canManage($request), 403);

        \App\Models\Expense::find($debt->expense_id)?->delete();
        $debt->delete(); // погашения уходят каскадом

        return back()->with('success', 'Долг удалён — расход на Финансах убран.');
    }

    public function destroyAdjustment(Request $request, PayrollAdjustment $adjustment): RedirectResponse
    {
        abort_unless($this->canManage($request), 403);
        // Аванс: удаляем и его расход на Финансах (деньги вернулись в кассу).
        if ($adjustment->expense_id) {
            \App\Models\Expense::find($adjustment->expense_id)?->delete();
        }
        $adjustment->delete();

        return back()->with('success', 'Корректировка удалена.');
    }

    /** Оклад вводит бухгалтер/админ прямо в ведомости. */
    public function updateSalary(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Оклад вводит бухгалтер или админ.');

        $data = $request->validate(['salary' => ['required', 'numeric', 'min:0', 'max:99999999']]);
        $user->update(['salary' => $data['salary']]);

        return back()->with('success', 'Оклад обновлён.');
    }

    /**
     * Отработанные часы сотрудника за месяц (почасовой оклад). Пустое значение —
     * удаляет запись, сотрудник возвращается на полный оклад.
     */
    public function updateHours(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Часы вводит бухгалтер или админ.');

        $data = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:744'],
            // Ночные часы: ночной час = дневная ставка × 1.5.
            'night_hours' => ['nullable', 'numeric', 'min:0', 'max:744'],
        ]);

        if ($data['hours'] === null && ($data['night_hours'] ?? null) === null) {
            WorkHour::where('user_id', $user->id)->where('month', $data['month'])->delete();

            return back()->with('success', 'Часы удалены — начисляется полный оклад.');
        }

        WorkHour::updateOrCreate(
            ['user_id' => $user->id, 'month' => $data['month']],
            ['hours' => $data['hours'], 'night_hours' => $data['night_hours'] ?? null, 'created_by' => $request->user()->id]
        );

        return back()->with('success', 'Отработанные часы сохранены.');
    }

    /**
     * Норма часов месяца (знаменатель ставки за час). Без department_id — общая
     * для всех; с department_id — своя норма отдела (цех 200 ч, менеджеры 220 ч…),
     * пустая norm сбрасывает отдел на общую норму.
     */
    public function updateNorm(Request $request): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Норму часов вводит бухгалтер или админ.');

        $data = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'norm' => ['nullable', 'required_without:department_id', 'numeric', 'min:1', 'max:744'],
            'department_id' => ['nullable', 'exists:departments,id'],
        ]);

        if (! empty($data['department_id'])) {
            $key = 'work_norm_'.$data['month'].':dept:'.$data['department_id'];
            if ($data['norm'] === null) {
                // first()?->delete() — событием модели сбрасывается кэш settings.all.
                Setting::where('key', $key)->first()?->delete();

                return back()->with('success', 'Норма отдела сброшена — действует общая норма месяца.');
            }
            Setting::set($key, $data['norm']);

            return back()->with('success', 'Норма часов отдела сохранена.');
        }

        Setting::set('work_norm_'.$data['month'], $data['norm']);
        // Запоминаем как значение по умолчанию — следующие месяцы предзаполнятся им.
        Setting::set('work_norm_default', $data['norm']);

        return back()->with('success', 'Норма часов на месяц сохранена.');
    }
}
