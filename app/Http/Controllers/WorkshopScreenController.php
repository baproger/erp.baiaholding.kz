<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\WorkshopScreen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ТВ-экран цеха (без логина): вход по коду из админки. Один код = один цех —
 * чужой цех с этого экрана не открыть. Денег на экране нет.
 */
class WorkshopScreenController extends Controller
{
    public function show(Request $request): Response
    {
        $screen = WorkshopScreen::with('company:id,name,code')
            ->where('is_active', true)->find($request->session()->get('workshop_screen_id'));
        // Код сверяем при каждом показе: «Новый код» в админке отключает
        // все экраны, вошедшие по старому коду.
        if ($screen && $screen->code !== $request->session()->get('workshop_screen_code')) {
            $screen = null;
        }
        if (! $screen) {
            $request->session()->forget('workshop_screen_id');

            return Inertia::render('Screen/Enter');
        }

        if ($screen->kind === 'office') {
            return $this->office($screen);
        }

        $companyId = $screen->company_id ? (int) $screen->company_id : null;
        $stages = ProjectStage::companyQuery($companyId, $screen->workshop)
            ->with('translations')->get()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName(), 'color' => $s->color, 'is_completed' => $s->is_completed]);

        $projects = Project::query()
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->when($companyId, fn ($q, $c) => $q->whereHas('deal', fn ($d) => $d->where('company_id', $c)))
            ->when($screen->workshop, fn ($q, $w) => $q->where('workshop', $w))
            ->with(['stage:id,name', 'deal:id,number,company_name,address,deadline,description,note'])
            ->addSelect(['stage_entered_at' => \App\Models\ProjectStageLog::select('entered_at')
                ->whereColumn('project_id', 'projects.id')->whereNull('left_at')
                ->latest('entered_at')->limit(1)])
            ->latest()->get()
            ->map(fn ($p) => [
                'id' => $p->id, 'number' => $p->number,
                'name' => $p->deal?->company_name ?: $p->name,
                'stage_id' => $p->project_stage_id,
                'address' => $p->deal?->address,
                'deadline' => optional($p->deal?->deadline ?? $p->deadline)->toDateString(),
                'overdue' => ($p->deal?->deadline ?? $p->deadline)?->isPast() ?? false,
                'description' => $p->deal?->description,
                'note' => $p->deal?->note,
                'stage_entered_at' => $p->stage_entered_at,
            ]);

        return Inertia::render('Screen/Workshop', [
            'screen' => ['workshop' => $screen->workshop, 'company' => $screen->company?->name],
            'stages' => $stages,
            'projects' => $projects,
        ]);
    }

    /**
     * Экран «Офис»: лидер — по ЭФФЕКТИВНОСТИ (принесённая компании прибыль
     * за месяц по won-сделкам, та же формула, что в ЗП), а не по числу сделок.
     * Денег на экране нет — только баллы (0–100 от лучшего), маржа %, штуки.
     * Фильтр месяца (?month=YYYY-MM) — кто был лучшим в любом месяце.
     */
    private function office(WorkshopScreen $screen): Response
    {
        $companyId = $screen->company_id ? (int) $screen->company_id : null;
        $plan = max(1, (int) \App\Models\Setting::get('sales_plan_monthly', 20));
        $month = preg_match('/^\d{4}-\d{2}$/', (string) request()->query('month'))
            ? request()->query('month') : now()->format('Y-m');
        $mStart = $month.'-01';
        $mEnd = \Illuminate\Support\Carbon::parse($mStart)->endOfMonth()->toDateString();
        $taxRate = ((float) \App\Models\Setting::get('tax_percent', 3)) / 100;

        // Сделки месяца: по дате договора (без неё — по дате создания) —
        // как фильтр «Месяц» на Финансах и в Сводном отчёте.
        $deals = \App\Models\Deal::query()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))
            ->whereNotNull('responsible_user_id')->where('status', '!=', 'cancelled')
            ->where(fn ($w) => $w->whereBetween('contract_date', [$mStart, $mEnd])
                ->orWhere(fn ($n) => $n->whereNull('contract_date')
                    ->whereDate('created_at', '>=', $mStart)->whereDate('created_at', '<=', $mEnd)))
            ->get(['id', 'budget', 'responsible_user_id', 'deal_stage_id']);

        $wonIds = \App\Models\DealStage::where('is_won', true)->pluck('id')->flip();
        $won = $deals->filter(fn ($d) => $wonIds->has($d->deal_stage_id));

        $expByDeal = \App\Models\Expense::where('status', 'confirmed')->where('expenseable_type', 'deal')
            ->whereIn('expenseable_id', $won->pluck('id'))
            ->groupBy('expenseable_id')->selectRaw('expenseable_id d, sum(amount) s')->pluck('s', 'd');
        $paidByDeal = \App\Models\Payment::query()
            ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->where('invoices.invoiceable_type', 'deal')
            ->whereIn('invoices.invoiceable_id', $won->pluck('id'))
            ->groupBy('invoices.invoiceable_id')
            ->selectRaw('invoices.invoiceable_id d, sum(payments.amount) s')->pluck('s', 'd');

        // По каждой won-сделке: прибыль компании = остаток − бонус (как в ЗП).
        $perUser = $won->groupBy('responsible_user_id')->map(function ($rows) use ($expByDeal, $paidByDeal, $taxRate) {
            $profit = 0.0; $margins = [];
            foreach ($rows as $d) {
                $budget = (float) $d->budget;
                $tax = round($budget * $taxRate, 2);
                $remainder = round($budget - $tax - (float) ($expByDeal[$d->id] ?? 0), 2);
                $ratio = $budget > 0 ? min(1, (float) ($paidByDeal[$d->id] ?? 0) / $budget) : 0;
                $bonus = round(\App\Services\PayrollService::marginBonus($budget, $remainder, $tax) * $ratio, 2);
                $profit += $remainder - $bonus;
                $margins[] = \App\Services\PayrollService::marginPct($budget, $remainder, $tax);
            }

            return ['profit' => $profit, 'won' => count($margins),
                'margin' => count($margins) ? round(array_sum($margins) / count($margins), 1) : 0.0];
        });
        $createdCounts = $deals->groupBy('responsible_user_id')->map->count();
        $maxProfit = max(1e-9, (float) $perUser->max('profit'));

        $managers = \App\Models\User::role('manager')->where('is_active', true)->get(['id', 'name', 'avatar'])
            ->map(function ($u) use ($perUser, $createdCounts, $maxProfit, $plan) {
                $m = $perUser[$u->id] ?? ['profit' => 0.0, 'won' => 0, 'margin' => 0.0];
                $total = (int) ($createdCounts[$u->id] ?? 0);

                return [
                    'name' => $u->name, 'avatar' => $u->avatar,
                    // Балл: % от прибыли лучшего менеджера (сама прибыль скрыта).
                    'score' => $m['profit'] > 0 ? (int) round($m['profit'] / $maxProfit * 100) : 0,
                    'margin' => $m['margin'],
                    'won' => $m['won'],
                    'total' => $total,
                    'conversion' => $total > 0 ? (int) round($m['won'] / $total * 100) : 0,
                    'plan_pct' => min(100, (int) round($total / $plan * 100)),
                ];
            })
            ->sortBy([['score', 'desc'], ['margin', 'desc'], ['total', 'desc']])->values();

        return Inertia::render('Screen/Office', [
            'screen' => ['company' => $screen->company?->name],
            'plan' => $plan,
            'month' => $month,
            'monthLabel' => \Illuminate\Support\Carbon::parse($mStart)->locale('ru')->translatedFormat('LLLL Y'),
            'managers' => $managers,
            'leader' => $managers->first(),
        ]);
    }

    /** План сделок на месяц для экрана «Офис» — ставит админ или финансист. */
    public function plan(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'financist']) || $request->user()->can('setting.update'), 403);
        $data = $request->validate(['plan' => ['required', 'integer', 'min:1', 'max:1000']]);
        \App\Models\Setting::set('sales_plan_monthly', $data['plan']);

        return back()->with('success', 'План сделок на месяц: '.$data['plan'].'.');
    }

    public function enter(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $screen = WorkshopScreen::where('code', trim($data['code']))->where('is_active', true)->first();
        if (! $screen) {
            return back()->withErrors(['code' => 'Неверный код. Проверьте код экрана у администратора.']);
        }
        $request->session()->put('workshop_screen_id', $screen->id);
        $request->session()->put('workshop_screen_code', $screen->code);

        return redirect()->route('screen.show');
    }

    public function leave(Request $request): RedirectResponse
    {
        $request->session()->forget('workshop_screen_id');

        return redirect()->route('screen.show');
    }

    /** Настройки → Экраны: все цеха всех компаний, коды и статусы. */
    public function admin(Request $request): Response
    {
        $this->guardAdmin($request);

        $screens = WorkshopScreen::get()->keyBy(fn ($s) => ($s->company_id ?? 0).'|'.($s->workshop ?? '').'|'.$s->kind);
        $companies = \App\Models\Company::orderBy('id')->get(['id', 'name'])->map(function ($c) use ($screens) {
            $stages = ProjectStage::where('company_id', $c->id)->where('is_active', true)->get(['workshop']);
            $rows = $stages->pluck('workshop')->filter()->unique()->values()
                ->map(fn ($w) => ['workshop' => $w, 'label' => $w]);
            if ($rows->isEmpty() || $stages->contains(fn ($s) => $s->workshop === null)) {
                $rows->push(['workshop' => null, 'label' => 'Единый цех']);
            }

            return [
                'id' => $c->id, 'name' => $c->name,
                'rows' => $rows->map(fn ($r) => $r + [
                    'screen' => ($sc = $screens->get($c->id.'|'.($r['workshop'] ?? '').'|workshop'))
                        ? ['id' => $sc->id, 'code' => $sc->code, 'is_active' => $sc->is_active] : null,
                ])->values(),
                'office' => ($sc = $screens->get($c->id.'||office'))
                    ? ['id' => $sc->id, 'code' => $sc->code, 'is_active' => $sc->is_active] : null,
            ];
        });

        return Inertia::render('Settings/Screens', [
            'companies' => $companies,
            'salesPlan' => (int) \App\Models\Setting::get('sales_plan_monthly', 20),
        ]);
    }

    /** Включить/выключить экран (код перестаёт работать сразу). */
    public function toggle(Request $request, WorkshopScreen $screen): RedirectResponse
    {
        $this->guardAdmin($request);
        $screen->update(['is_active' => ! $screen->is_active]);

        return back()->with('success', $screen->is_active ? 'Экран включён.' : 'Экран отключён.');
    }

    private function guardAdmin(Request $request): void
    {
        abort_unless($request->user()->hasRole('admin') || $request->user()->can('setting.update'), 403);
    }

    /** Админка: выдать/перегенерировать код экрана цеха. */
    public function upsert(Request $request): RedirectResponse
    {
        $this->guardAdmin($request);
        $data = $request->validate([
            'company_id' => ['nullable', 'exists:companies,id'],
            'workshop' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', \Illuminate\Validation\Rule::in(['workshop', 'office'])],
        ]);

        WorkshopScreen::updateOrCreate(
            ['company_id' => $data['company_id'] ?? null, 'workshop' => $data['workshop'] ?? null, 'kind' => $data['kind'] ?? 'workshop'],
            ['code' => WorkshopScreen::freshCode(), 'is_active' => true]
        );

        return back()->with('success', 'Код экрана обновлён.');
    }
}
