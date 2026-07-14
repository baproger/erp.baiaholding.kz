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
    public function deals(Request $request): Response
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'director']), 403);

        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;

        $search = $request->string('search')->toString();
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        $managerId = $request->integer('manager') ?: null;
        $stageId = $request->integer('stage') ?: null;

        $deals = Deal::forCurrentCompany()
            ->where('status', '!=', 'cancelled')
            ->with(['responsible:id,name', 'stage:id,name,color,is_won,stage_type'])
            ->when($search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('number', 'like', "%{$s}%")->orWhere('company_name', 'like', "%{$s}%")
                ->orWhere('client_name', 'like', "%{$s}%")->orWhere('bin', 'like', "%{$s}%")
                ->orWhere('address', 'like', "%{$s}%")))
            ->when($managerId, fn ($q, $m) => $q->where('responsible_user_id', $m))
            ->when($stageId, fn ($q, $s) => $q->where('deal_stage_id', $s))
            ->when($from, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($to, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest()
            ->get(['id', 'number', 'bin', 'company_name', 'address', 'client_name', 'lot_number', 'unit',
                'budget', 'deadline', 'deal_stage_id', 'responsible_user_id', 'status', 'created_at']);

        // Оплачено по сделке — платежи по её счетам (одним запросом на всех).
        $paidByDeal = Payment::join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->where('invoices.invoiceable_type', 'deal')
            ->whereIn('invoices.invoiceable_id', $deals->pluck('id'))
            ->groupBy('invoices.invoiceable_id')
            ->selectRaw('invoices.invoiceable_id as deal_id, sum(payments.amount) as paid')
            ->pluck('paid', 'deal_id');

        // Подтверждённые расходы: закуп со склада (material_id) и прочие — раздельно.
        $expByDeal = Expense::where('status', 'confirmed')->where('expenseable_type', 'deal')
            ->whereIn('expenseable_id', $deals->pluck('id'))
            ->groupBy('expenseable_id')
            ->selectRaw('expenseable_id as deal_id,
                sum(case when material_id is not null then amount else 0 end) as material,
                sum(case when material_id is null then amount else 0 end) as other')
            ->get()->keyBy('deal_id');

        $rows = $deals->map(function ($d) use ($paidByDeal, $expByDeal, $taxRate) {
            $budget = (float) $d->budget;
            $material = (float) ($expByDeal[$d->id]->material ?? 0);
            $other = (float) ($expByDeal[$d->id]->other ?? 0);
            $expense = $material + $other;
            $tax = round($budget * $taxRate, 2);
            $remainder = round($budget - $tax - $expense, 2);
            // Та же ступенчатая формула бонуса, что на карточке сделки и в ЗП.
            $bonus = PayrollService::marginBonus($budget, $remainder, $tax);
            $company = round($remainder - $bonus, 2);

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
                'other' => $other,
                'tax' => $tax,
                'remainder' => $remainder,
                'margin' => PayrollService::marginPct($budget, $remainder, $tax),
                'bonus' => $bonus,
                'company' => $company,
                'manager' => $d->responsible?->name,
                'deadline' => optional($d->deadline)->toDateString(),
                'stage' => $d->stage?->name,
                'stage_color' => $d->stage?->color,
                'is_won' => (bool) $d->stage?->is_won,
                // Группы подсветки по stage_type (имя этапа ненадёжно):
                // Акт/ЭСФ — зелёные как won; Логистика/Сборка — жёлтые.
                'is_pending_won' => in_array($d->stage?->stage_type, ['act', 'esf'], true),
                'is_logistics' => in_array($d->stage?->stage_type, ['logistics', 'assembly'], true),
            ];
        })->values();

        $budgetSum = $rows->sum('budget');
        $companySum = $rows->sum('company');
        $totals = [
            'budget' => $budgetSum,
            'paid' => $rows->sum('paid'),
            'material' => $rows->sum('material'),
            'other' => $rows->sum('other'),
            'tax' => $rows->sum('tax'),
            'remainder' => $rows->sum('remainder'),
            'bonus' => $rows->sum('bonus'),
            'company' => $companySum,
            'margin' => $budgetSum > 0 ? round($companySum / $budgetSum * 100, 1) : 0,
            'count' => $rows->count(),
        ];

        // Опции фильтров: активные менеджеры и этапы воронки текущей компании
        // (в режиме «Все компании» — обе воронки с пометкой фирмы).
        $companyId = \App\Support\CurrentCompany::id() ?: null;
        $companyNames = \App\Models\Company::pluck('name', 'id');
        $stageOptions = \App\Models\DealStage::with('translations')->where('is_active', true)
            ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w->where('company_id', $c)->orWhereNull('company_id')))
            ->orderBy('order')->get()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName().(! $companyId && $s->company_id ? ' · '.($companyNames[$s->company_id] ?? '') : '')])
            ->values();

        return Inertia::render('Reports/Deals', [
            'rows' => $rows,
            'totals' => $totals,
            'taxRate' => $taxRate * 100,
            'filters' => ['search' => $search, 'from' => $from, 'to' => $to, 'manager' => $managerId, 'stage' => $stageId],
            'managers' => \App\Models\User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stageOptions' => $stageOptions,
        ]);
    }
}
