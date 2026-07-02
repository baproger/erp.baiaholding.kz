<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\Payment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('report.viewAny') || $request->user()->hasRole('admin'), 403);

        // Deals by stage (funnel).
        $stages = DealStage::with('translations')->where('is_active', true)->orderBy('order')->get();
        $dealsByStage = Deal::query()
            ->selectRaw('deal_stage_id, count(*) as cnt, coalesce(sum(budget),0) as total')
            ->groupBy('deal_stage_id')->get()->keyBy('deal_stage_id');

        $funnel = $stages->map(fn ($s) => [
            'name' => $s->translatedName(),
            'color' => $s->color,
            'count' => (int) ($dealsByStage[$s->id]->cnt ?? 0),
            'total' => (float) ($dealsByStage[$s->id]->total ?? 0),
        ])->values();

        // Deals by status.
        $byStatus = Deal::query()->selectRaw('status, count(*) as cnt')->groupBy('status')
            ->pluck('cnt', 'status');

        // Monthly income (payments) and expenses — grouped in PHP for DB portability.
        $months = collect(range(5, 0))->map(fn ($i) => now()->subMonths($i)->format('Y-m'));
        $payments = Payment::query()->get(['amount', 'payment_date']);
        $expenses = Expense::query()->where('status', 'confirmed')->get(['amount', 'date']);

        $monthly = $months->map(function ($m) use ($payments, $expenses) {
            $income = $payments->filter(fn ($p) => optional($p->payment_date)->format('Y-m') === $m)->sum('amount');
            $expense = $expenses->filter(fn ($e) => optional($e->date)->format('Y-m') === $m)->sum('amount');

            return ['month' => $m, 'income' => (float) $income, 'expense' => (float) $expense];
        });

        // Top clients by total deal budget.
        $topClients = Client::query()
            ->withSum('deals as deals_total', 'budget')
            ->withCount('deals')
            ->orderByDesc('deals_total')
            ->limit(5)->get(['id', 'name'])
            ->map(fn ($c) => ['name' => $c->name, 'total' => (float) ($c->deals_total ?? 0), 'deals' => $c->deals_count]);

        // Conversion: won deals vs total.
        $wonStageIds = DealStage::where('is_won', true)->pluck('id');
        $total = Deal::count();
        $won = Deal::whereIn('deal_stage_id', $wonStageIds)->count();

        // ABC analysis by ACTUAL income (paid), A≤80% / B≤95% / C rest of cumulative value.
        $dealIncome = Payment::query()
            ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->where('invoices.invoiceable_type', 'deal')
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

        return Inertia::render('Analytics/Index', [
            'funnel' => $funnel,
            'byStatus' => $byStatus,
            'monthly' => $monthly,
            'topClients' => $topClients,
            'abc' => $abc->take(20)->values(),
            'abcSummary' => $abcSummary,
            'conversion' => [
                'total' => $total,
                'won' => $won,
                'rate' => $total > 0 ? round($won / $total * 100, 1) : 0,
            ],
            'totals' => [
                'income' => (float) $payments->sum('amount'),
                'expense' => (float) $expenses->sum('amount'),
            ],
        ]);
    }
}
