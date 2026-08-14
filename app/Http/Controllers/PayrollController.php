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
        $hoursByUser = WorkHour::where('month', $month)->pluck('hours', 'user_id');

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
            $deductions = round((float) $adj->whereIn('type', PayrollAdjustment::DEDUCTIONS)->sum('amount'), 2);
            $additions = round((float) $adj->where('type', 'bonus')->sum('amount'), 2);
            $r['adjustments'] = $adj->map(fn ($a) => [
                'id' => $a->id, 'type' => $a->type, 'days' => $a->days !== null ? (float) $a->days : null,
                'amount' => (float) $a->amount, 'date' => optional($a->date)->toDateString(),
                'created_at' => optional($a->created_at)->toIso8601String(),
                'note' => $a->note, 'creator' => $a->creator?->name,
            ])->values();
            $r['deductions'] = $deductions;
            $r['additions'] = $additions;

            // Почасовая база: часы введены → часы × (оклад ÷ норма отдела или общая); нет — полный оклад.
            $deptId = $deptByUser[$r['uid']]?->department_id;
            $norm = (float) ($deptNorms[$deptId] ?? $normHours);
            $hours = isset($hoursByUser[$r['uid']]) ? (float) $hoursByUser[$r['uid']] : null;
            $rate = $norm > 0 ? $r['salary'] / $norm : 0.0;
            $r['hours'] = $hours;
            $r['hourly_rate'] = $norm > 0 ? round($rate, 2) : null;
            $r['base'] = $hours !== null && $norm > 0 ? round($hours * $rate, 2) : $r['salary'];
            // К выплате = почасовая база (или оклад) + бонус − удержания + премии.
            $r['payout'] = round($r['base'] + $r['bonus'], 2);
            $r['final'] = round($r['payout'] - $deductions + $additions, 2);
            // Долг: удержание ТОЛЬКО из бонуса месяца и не больше месячного
            // платежа. Оклад не трогаем — нет бонуса, нет и удержания.
            $plan = $debtPlans[$r['uid']] ?? null;
            $r['debt_charge'] = $plan['charge'] ?? 0.0;
            $r['debt_planned'] = $plan['planned'] ?? 0.0;
            $r['debt_remaining'] = $plan['before'] ?? 0.0;
            $r['debt_after'] = $plan['after'] ?? 0.0;
            $r['debts'] = $debtList[$r['uid']] ?? [];
            $r['final'] = round($r['final'] - $r['debt_charge'], 2);
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

        return Inertia::render('Payroll/Index', [
            'rows' => $rows,
            'leadership' => $leadership,
            'canManage' => $this->canManage($request),
            'month' => $month,
            'normHours' => $normHours,
            'deptNorms' => $deptNorms,
            'taxRate' => $taxRate * 100,
            'companies' => \App\Models\Company::where('is_active', true)->orderBy('id')->get(['id', 'name']),
            // Отделы всех фирм: по ним ведомость раскладывает сотрудника
            // в отдел ЕГО фирмы (у «двойного» department_id указывает на одну).
            'departments' => \App\Models\Department::where('is_active', true)
                ->orderBy('company_id')->orderBy('name')->get(['id', 'company_id', 'name', 'code']),
            'totals' => [
                'budget' => (float) $rows->sum('budget'),
                'tax' => (float) $rows->sum('tax'),
                'expense' => (float) $rows->sum('expense'),
                'bonus' => (float) $rows->sum('bonus'),
                'bonus_month' => (float) $rows->sum('bonus_month'),
                'salary' => (float) $rows->sum('salary'),
                'base' => (float) $rows->sum('base'),
                'payout' => (float) $rows->sum('payout'),
                'deductions' => (float) $rows->sum('deductions'),
                'additions' => (float) $rows->sum('additions'),
                'debt_charge' => (float) $rows->sum('debt_charge'),
                'debt_remaining' => (float) $rows->sum('debt_remaining'),
                'final' => (float) $rows->sum('final'),
                'company' => (float) $rows->sum('company'),
            ],
        ]);
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
        ]);

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
        if ($data['type'] === 'advance') {
            $employee = User::find($data['user_id']);
            $category = \App\Models\ExpenseCategory::firstOrCreate(
                ['name' => 'Расходы по сотрудникам'],
                ['is_active' => true]
            );
            $expense = \App\Models\Expense::create([
                'company_id' => \App\Support\CurrentCompany::id()
                    ?: $employee->companies()->value('companies.id'),
                'category_id' => $category->id,
                'type' => 'direct',
                'amount' => $data['amount'],
                'date' => $data['date'],
                'description' => 'Аванс сотруднику: '.$employee->name
                    .(! empty($data['note']) ? ' — '.$data['note'] : ''),
                'responsible_user_id' => $employee->id,
                // Явная связь «кому выдали»: responsible_user_id у расхода по
                // сделке значит «кто потратил», поэтому смыслы разведены.
                'employee_id' => $employee->id,
                'employee_payout' => 'advance',
                'status' => 'confirmed',
                'payment_method' => $data['payment_method'] ?? 'cash',
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now(),
            ]);
            $data['expense_id'] = $expense->id;
            $data['payment_method'] = $data['payment_method'] ?? 'cash';
        }

        PayrollAdjustment::create($data);

        return back()->with('success', $data['type'] === 'advance'
            ? 'Аванс добавлен и зафиксирован в Расходах на Финансах.'
            : 'Корректировка добавлена.');
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
            ['name' => 'Расходы по сотрудникам'],
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
        ]);

        if ($data['hours'] === null) {
            WorkHour::where('user_id', $user->id)->where('month', $data['month'])->delete();

            return back()->with('success', 'Часы удалены — начисляется полный оклад.');
        }

        WorkHour::updateOrCreate(
            ['user_id' => $user->id, 'month' => $data['month']],
            ['hours' => $data['hours'], 'created_by' => $request->user()->id]
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
