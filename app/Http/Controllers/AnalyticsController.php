<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('report.viewAny') || $request->user()->hasRole('admin'), 403);

        $wonIds = Deal::won()->forCurrentCompany()->pluck('id');

        // Deals by stage (funnel).
        $stages = DealStage::with('translations')->where('is_active', true)->orderBy('order')->get();
        $dealsByStage = Deal::query()->forCurrentCompany()
            ->selectRaw('deal_stage_id, count(*) as cnt, coalesce(sum(budget),0) as total')
            ->groupBy('deal_stage_id')->get()->keyBy('deal_stage_id');

        $funnel = $stages->map(fn ($s) => [
            'name' => $s->translatedName(),
            'color' => $s->color,
            'count' => (int) ($dealsByStage[$s->id]->cnt ?? 0),
            'total' => (float) ($dealsByStage[$s->id]->total ?? 0),
        ])->values();

        // Deals by status.
        $byStatus = Deal::query()->forCurrentCompany()->selectRaw('status, count(*) as cnt')->groupBy('status')
            ->pluck('cnt', 'status');

        // Monthly income (payments) and expenses — grouped in PHP for DB portability.
        $monthsCount = in_array((int) $request->integer('months', 6), [3, 6, 12], true) ? (int) $request->integer('months', 6) : 6;
        $months = collect(range($monthsCount - 1, 0))->map(fn ($i) => now()->subMonths($i)->format('Y-m'));
        $payments = Payment::whereHas('invoice', fn ($q) => $q->where('invoiceable_type', 'deal')->whereIn('invoiceable_id', $wonIds))->get(['amount', 'payment_date']);
        $expenses = Expense::where('status', 'confirmed')->where('expenseable_type', 'deal')->whereIn('expenseable_id', $wonIds)->get(['amount', 'date']);

        $monthly = $months->map(function ($m) use ($payments, $expenses) {
            $income = $payments->filter(fn ($p) => optional($p->payment_date)->format('Y-m') === $m)->sum('amount');
            $expense = $expenses->filter(fn ($e) => optional($e->date)->format('Y-m') === $m)->sum('amount');

            return ['month' => $m, 'income' => (float) $income, 'expense' => (float) $expense];
        });

        // Conversion: won deals vs total.
        $wonStageIds = DealStage::where('is_won', true)->pluck('id');
        $total = Deal::query()->forCurrentCompany()->count();
        // Отменённые сделки, оставшиеся на won-этапе, успехом не считаются.
        $won = Deal::query()->forCurrentCompany()->whereIn('deal_stage_id', $wonStageIds)->where('status', '!=', 'cancelled')->count();

        // ABC analysis by ACTUAL income (paid), A≤80% / B≤95% / C rest of cumulative value.
        $dealIncome = Payment::query()
            ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->where('invoices.invoiceable_type', 'deal')
            ->whereIn('invoices.invoiceable_id', $wonIds)
            ->whereNotNull('invoices.invoiceable_id')
            ->groupBy('invoices.invoiceable_id')
            ->selectRaw('invoices.invoiceable_id as deal_id, SUM(payments.amount) as income')
            ->pluck('income', 'deal_id');

        $ranked = Deal::whereIn('id', $dealIncome->keys())->get(['id', 'number', 'name'])
            ->map(fn ($d) => ['number' => $d->number, 'name' => $d->name, 'value' => (float) $dealIncome[$d->id]])
            ->sortByDesc('value')->values();
        $totalIncome = (float) $ranked->sum('value');
        $cumulative = 0.0;
        $abc = $ranked->map(function ($row) use (&$cumulative, $totalIncome) {
            $value = (float) $row['value'];
            $share = $totalIncome > 0 ? $value / $totalIncome * 100 : 0;
            $cumulative += $share;
            $class = $cumulative <= 80 ? 'A' : ($cumulative <= 95 ? 'B' : 'C');

            return [
                'number' => $row['number'], 'name' => $row['name'], 'value' => $value,
                'share' => round($share, 1), 'cumulative' => round($cumulative, 1), 'class' => $class,
            ];
        });
        $abcSummary = collect(['A', 'B', 'C'])->mapWithKeys(fn ($c) => [$c => [
            'count' => $abc->where('class', $c)->count(),
            'value' => round($abc->where('class', $c)->sum('value'), 2),
        ]]);

        // ---- Per-employee analytics (deals by stage + overdue + ЗП/margin) ----
        $payroll = app(PayrollService::class);
        $salaryRows = $payroll->perUser();
        $companyTotals = $payroll->companyTotals();
        // «На подходе» = Акт + ЭСФ (по имени, у каждой компании своя воронка).
        $allStages = DealStage::where('is_active', true)->orderBy('order')->get();
        $pendingIds = $allStages->filter(fn ($s) => mb_stripos($s->name, 'акт') !== false || mb_stripos($s->name, 'эсф') !== false)->pluck('id');
        if ($pendingIds->isEmpty() && ($fallback = $allStages->slice(-2, 1)->first())) {
            $pendingIds = collect([$fallback->id]);
        }
        $today = now()->startOfDay();
        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;

        $empDealsRaw = Deal::query()->forCurrentCompany()
            ->whereNotNull('responsible_user_id')
            ->where('status', '!=', 'cancelled')
            ->where(function ($w) use ($wonStageIds, $pendingIds, $today) {
                $w->whereIn('deal_stage_id', $wonStageIds)
                    ->orWhereIn('deal_stage_id', $pendingIds)
                    ->orWhere(fn ($o) => $o->whereNotNull('deadline')->whereDate('deadline', '<', $today)
                        ->whereNotIn('status', ['closed', 'cancelled'])
                        ->whereDoesntHave('stage', fn ($s) => $s->where('is_won', true)));
            })
            ->get(['id', 'number', 'company_name', 'budget', 'deadline', 'deal_stage_id', 'responsible_user_id', 'status']);

        // Confirmed expenses per deal → per-deal margin (same formula as the deal card).
        $dealExpense = Expense::where('status', 'confirmed')->where('expenseable_type', 'deal')
            ->whereIn('expenseable_id', $empDealsRaw->pluck('id'))
            ->groupBy('expenseable_id')->selectRaw('expenseable_id as did, sum(amount) as v')->pluck('v', 'did');

        $empDeals = $empDealsRaw->groupBy('responsible_user_id');

        $wonIdsList = $wonStageIds->all();
        $mapDeal = function ($d) use ($dealExpense, $taxRate) {
            $budget = (float) $d->budget;
            $tax = round($budget * $taxRate, 2);
            $expense = (float) ($dealExpense[$d->id] ?? 0);
            $remainder = round($budget - $tax - $expense, 2);
            // Ступенчатый бонус от маржи сделки (см. PayrollService::bonusRateForMargin).
            $bonus = PayrollService::marginBonus($budget, $remainder, $tax);
            $company = round($remainder - $bonus, 2);

            return [
                'id' => $d->id,
                'number' => $d->number,
                'company' => $d->company_name,
                'budget' => $budget,
                'net' => $company,
                'margin' => $budget > 0 ? round($company / $budget * 100, 1) : 0.0,
                'deadline' => optional($d->deadline)->toDateString(),
            ];
        };

        $byEmployee = $salaryRows->map(function ($row) use ($empDeals, $wonIdsList, $pendingIds, $today, $mapDeal) {
            $deals = $empDeals->get($row['uid'], collect());
            $won = $deals->whereIn('deal_stage_id', $wonIdsList)->map($mapDeal)->values();
            $act = $deals->whereIn('deal_stage_id', $pendingIds->all())->map($mapDeal)->values();
            $overdue = $deals->filter(fn ($d) => $d->deadline && $d->deadline->startOfDay() < $today
                    && ! in_array($d->deal_stage_id, $wonIdsList) && ! in_array($d->status, ['closed', 'cancelled']))
                ->map(fn ($d) => array_merge($mapDeal($d), ['overdue_days' => (int) $d->deadline->startOfDay()->diffInDays($today)]))
                ->sortByDesc('overdue_days')->values();

            return [
                'uid' => $row['uid'],
                'user' => $row['user'],
                'avatar' => $row['avatar'],
                'income' => $row['income'],
                'expense' => $row['expense'],
                'net' => $row['net'],
                'tax' => $row['tax'],
                'bonus' => $row['bonus'],
                'margin' => $row['margin'],
                'closed' => $row['closed'],
                'won_deals' => $won,
                'act_deals' => $act,
                'overdue_deals' => $overdue,
            ];
        })->sortByDesc('bonus')->values();

        return Inertia::render('Analytics/Index', [
            'byEmployee' => $byEmployee,
            'monthsFilter' => $monthsCount,
            'funnel' => $funnel,
            'byStatus' => $byStatus,
            'monthly' => $monthly,
            'abc' => $abc->take(20)->values(),
            'abcSummary' => $abcSummary,
            'conversion' => [
                'total' => $total,
                'won' => $won,
                'rate' => $total > 0 ? round($won / $total * 100, 1) : 0,
            ],
            // Canonical company figures (identical to Dashboard & Finance).
            'totals' => array_merge($companyTotals, ['net' => $companyTotals['company']]),
        ]);
    }
}
