<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Material;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Services\PayrollService;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Дашборд руководителя (admin/director/financist): деньги → где горит →
 * где затор в воронке → кто работает. Всё на одном экране, каждая плитка —
 * ссылка вглубь. Поиск по сделкам и фильтр по периоду (менеджеры, за период).
 */
class DashboardController extends Controller
{
    /** Морф-условие «сущность принадлежит текущей компании» (deal | project). */
    private function morphCompanyScope($q, string $typeCol, string $idCol, int $companyId): void
    {
        $q->where(fn ($w) => $w
            ->where(fn ($d) => $d->where($typeCol, 'deal')
                ->whereIn($idCol, Deal::where('company_id', $companyId)->select('id')))
            ->orWhere(fn ($p) => $p->where($typeCol, 'project')
                ->whereIn($idCol, Project::whereHas('deal', fn ($d) => $d->where('company_id', $companyId))->select('id'))));
    }

    public function index(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        $u = $request->user();
        if (! $u->hasAnyRole(['admin', 'director', 'financist'])) {
            return redirect()->route($u->hasRole('manager') ? 'deals.index' : 'projects.index');
        }

        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;
        $companyId = CurrentCompany::id();
        $today = now()->startOfDay();

        // Canonical company figures (shared with Analytics & Finance via PayrollService).
        $fin = app(PayrollService::class)->companyTotals();

        // Дебиторка: выставлено − оплачено (как на Финансах).
        $invBase = Invoice::query()->when($companyId, fn ($q, $c) => $this->morphCompanyScope($q, 'invoiceable_type', 'invoiceable_id', $c));
        $invoiced = (float) (clone $invBase)->sum('amount');
        $invoicePaid = (float) Payment::whereIn('invoice_id', (clone $invBase)->select('id'))->sum('amount');

        // ---- «Требует внимания» ----
        $overdueDeals = Deal::forCurrentCompany()->whereNotNull('deadline')->whereDate('deadline', '<', $today)
            ->whereNotIn('status', ['closed', 'cancelled'])
            ->whereDoesntHave('stage', fn ($s) => $s->where('is_won', true))->count();

        $overdueTasks = Task::where('status', '!=', 'done')->whereNotNull('due_date')->where('due_date', '<', now())
            ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w
                ->whereNull('taskable_type') // личные задачи не делятся по фирмам
                ->orWhere(fn ($m) => $this->morphCompanyScope($m, 'taskable_type', 'taskable_id', $c))))
            ->count();

        $expBase = Expense::query()->when($companyId, fn ($q, $c) => $this->morphCompanyScope($q, 'expenseable_type', 'expenseable_id', $c));
        $pendingExpenses = [
            'count' => (clone $expBase)->where('status', 'pending')->count(),
            'sum' => (float) (clone $expBase)->where('status', 'pending')->sum('amount'),
        ];

        $zeroMaterials = Material::forCurrentCompany()->where('quantity', '<=', 0)->count();

        // ---- Воронка: активные сделки по этапам (где затор) ----
        $stageCounts = Deal::forCurrentCompany()->whereNotIn('status', ['closed', 'cancelled'])
            ->selectRaw('deal_stage_id, count(*) c, sum(budget) s')
            ->groupBy('deal_stage_id')->get()->keyBy('deal_stage_id');
        $funnel = DealStage::funnel($companyId ?: null)->map(fn ($st) => [
            'name' => $st->name, 'color' => $st->color,
            'count' => (int) ($stageCounts[$st->id]->c ?? 0),
            'sum' => (float) ($stageCounts[$st->id]->s ?? 0),
        ])->values();

        // ---- Период (по умолчанию текущий месяц): факт денег + менеджеры ----
        $from = $request->string('from')->toString() ?: now()->startOfMonth()->toDateString();
        $to = $request->string('to')->toString() ?: now()->toDateString();

        $period = [
            'paid' => (float) Payment::whereIn('invoice_id', (clone $invBase)->select('id'))
                ->whereDate('payment_date', '>=', $from)->whereDate('payment_date', '<=', $to)->sum('amount'),
            'expenses' => (float) (clone $expBase)->where('status', 'confirmed')
                ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->sum('amount'),
            'newDeals' => Deal::forCurrentCompany()->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->count(),
        ];

        // Топ менеджеров за период: созданные сделки + бюджет.
        $topManagers = Deal::forCurrentCompany()->whereNotIn('status', ['cancelled'])
            ->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)
            ->whereNotNull('responsible_user_id')
            ->selectRaw('responsible_user_id, count(*) deals, sum(budget) budget')
            ->groupBy('responsible_user_id')->orderByDesc('budget')->limit(5)
            ->with('responsible:id,name')->get()
            ->map(fn ($r) => ['user' => $r->responsible?->name ?? '—', 'deals' => (int) $r->deals, 'budget' => (float) $r->budget]);

        // ---- Сделки: поиск по №, контрагенту, договору, товару ----
        $search = $request->string('search')->toString();
        $recent = Deal::forCurrentCompany()->with('stage:id,name,color,is_won')
            ->where('status', '!=', 'cancelled')
            ->when($search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('number', 'like', "%{$s}%")->orWhere('company_name', 'like', "%{$s}%")
                ->orWhere('client_name', 'like', "%{$s}%")->orWhere('bin', 'like', "%{$s}%")))
            ->latest()->limit($search ? 20 : 8)
            ->get(['id', 'number', 'company_name', 'bin', 'client_name', 'budget', 'deadline', 'deal_stage_id', 'status'])
            ->map(function ($d) use ($taxRate, $today) {
                $overdueDays = ($d->deadline && $d->deadline->startOfDay() < $today && ! optional($d->stage)->is_won && ! in_array($d->status, ['closed', 'cancelled']))
                    ? (int) $d->deadline->startOfDay()->diffInDays($today) : 0;

                return [
                    'id' => $d->id, 'number' => $d->number, 'company' => $d->company_name, 'bin' => $d->bin,
                    'budget' => (float) $d->budget, 'net' => round((float) $d->budget * (1 - $taxRate), 2),
                    'deadline' => optional($d->deadline)->toDateString(), 'overdue_days' => $overdueDays,
                    'stage' => optional($d->stage)->name, 'color' => optional($d->stage)->color,
                ];
            })->sortByDesc('overdue_days')->values();

        return Inertia::render('Dashboard', [
            'metrics' => [
                'total' => $fin['budget'],
                'paid' => $fin['income'],
                'expense' => $fin['expense'],
                'salaries' => $fin['bonus'],
                'net' => $fin['company'],
                'debt' => max(0, $invoiced - $invoicePaid),
                'taxRate' => $taxRate * 100,
            ],
            'attention' => [
                'overdueDeals' => $overdueDeals,
                'overdueTasks' => $overdueTasks,
                'pendingExpenses' => $pendingExpenses,
                'zeroMaterials' => $zeroMaterials,
            ],
            'funnel' => $funnel,
            'period' => $period,
            'topManagers' => $topManagers,
            'recent' => $recent,
            'filters' => ['search' => $search, 'from' => $from, 'to' => $to],
        ]);
    }
}
