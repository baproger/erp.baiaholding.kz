<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;

class PayrollService
{
    /**
     * Per-manager money breakdown (factual — only won/paid deals):
     *   net    = income − expense
     *   tax    = tax_percent% of the manager's won-deal budget
     *   company = (net − tax) split so the employee bonus is bonus_percent% of it
     *   bonus (ЗП) = bonus_percent% of the company's kept share
     *   margin = net / income %
     *
     * @return Collection<int, array<string, mixed>>
     */
    /**
     * Canonical company-wide finance figures over WON deals — the single source of
     * truth shared by Dashboard, Analytics and Finance so every page shows the same
     * numbers. All values are factual (won stage = «Оплата успешно»).
     *
     *   budget    = Σ won-deal budgets
     *   income    = Σ payments on won deals (factual money in)
     *   expense   = Σ confirmed expenses on won deals
     *   tax       = tax_percent% of budget
     *   remainder = budget − tax − expense
     *   bonus     = Σ per-manager ЗП (only deals with a responsible manager)
     *   company   = remainder − bonus  (company net profit)
     *
     * @return array<string, float>
     */
    public function companyTotals(): array
    {
        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;
        $wonIds = Deal::won()->pluck('id');

        $budget = (float) Deal::whereIn('id', $wonIds)->sum('budget');
        $income = (float) Payment::whereHas('invoice', fn ($q) => $q->where('invoiceable_type', 'deal')->whereIn('invoiceable_id', $wonIds))->sum('amount');
        $expense = (float) Expense::where('status', 'confirmed')->where('expenseable_type', 'deal')->whereIn('expenseable_id', $wonIds)->sum('amount');
        $tax = round($budget * $taxRate, 2);
        $remainder = round($budget - $tax - $expense, 2);
        $bonus = round($this->perUser()->sum('bonus'), 2);
        $company = round($remainder - $bonus, 2);

        return compact('budget', 'income', 'expense', 'tax', 'remainder', 'bonus', 'company');
    }

    /**
     * Per-deal breakdown for the payroll screen, grouped by responsible user.
     * Includes deals on «Оплата успешно» (won — counted in ЗП) and «Акт утверждение»
     * (pending — shown so the financist sees what is about to land). Each deal carries
     * the same money math as the deal card, so the ЗП figure can be verified line by line.
     *
     * @return Collection<int, Collection<int, array<string, mixed>>>  keyed by user id
     */
    public function dealBreakdown(): Collection
    {
        $rate = ((float) Setting::get('bonus_percent', 10)) / 100;
        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;

        $stages = DealStage::where('is_active', true)->orderBy('order')->get();
        $wonStageIds = $stages->where('is_won', true)->pluck('id');
        $actStage = $stages->slice(-2, 1)->first();
        $stageNames = $stages->pluck('name', 'id');

        $stageFilter = $wonStageIds->all();
        if ($actStage) {
            $stageFilter[] = $actStage->id;
        }

        $deals = Deal::whereNotNull('responsible_user_id')
            ->whereIn('deal_stage_id', $stageFilter)
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('budget')
            ->get(['id', 'number', 'company_name', 'budget', 'deal_stage_id', 'responsible_user_id', 'status']);

        $ids = $deals->pluck('id');
        $paidByDeal = Payment::query()
            ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->where('invoices.invoiceable_type', 'deal')
            ->whereIn('invoices.invoiceable_id', $ids)
            ->groupBy('invoices.invoiceable_id')
            ->selectRaw('invoices.invoiceable_id as did, SUM(payments.amount) as v')->pluck('v', 'did');
        $expenseByDeal = Expense::where('status', 'confirmed')->where('expenseable_type', 'deal')
            ->whereIn('expenseable_id', $ids)
            ->groupBy('expenseable_id')->selectRaw('expenseable_id as did, SUM(amount) as v')->pluck('v', 'did');

        return $deals->map(function ($d) use ($paidByDeal, $expenseByDeal, $taxRate, $rate, $wonStageIds, $stageNames) {
            $budget = (float) $d->budget;
            $paid = (float) ($paidByDeal[$d->id] ?? 0);
            $expense = (float) ($expenseByDeal[$d->id] ?? 0);
            $tax = round($budget * $taxRate, 2);
            $remainder = round($budget - $tax - $expense, 2);
            $bonus = $remainder > 0 ? round($remainder * $rate, 2) : 0.0;

            return [
                'uid' => (int) $d->responsible_user_id,
                'id' => $d->id,
                'number' => $d->number,
                'company' => $d->company_name,
                'stage' => $stageNames[$d->deal_stage_id] ?? '—',
                'is_won' => $wonStageIds->contains($d->deal_stage_id),
                'budget' => $budget,
                'paid' => $paid,
                'expense' => $expense,
                'tax' => $tax,
                'bonus' => $bonus,
                'net' => round($remainder - $bonus, 2),
            ];
        })->groupBy('uid');
    }

    public function perUser(): Collection
    {
        $rate = ((float) Setting::get('bonus_percent', 10)) / 100;
        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;
        $wonIds = Deal::won()->pluck('id');

        $incomeByUser = Payment::query()
            ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->join('deals', fn ($j) => $j->on('invoices.invoiceable_id', '=', 'deals.id')->where('invoices.invoiceable_type', 'deal'))
            ->whereIn('deals.id', $wonIds)
            ->groupBy('deals.responsible_user_id')
            ->selectRaw('deals.responsible_user_id as uid, SUM(payments.amount) as v')->pluck('v', 'uid');

        $expenseByUser = Expense::query()
            ->join('deals', fn ($j) => $j->on('expenses.expenseable_id', '=', 'deals.id')->where('expenses.expenseable_type', 'deal'))
            ->whereIn('deals.id', $wonIds)
            ->where('expenses.status', 'confirmed')
            ->groupBy('deals.responsible_user_id')
            ->selectRaw('deals.responsible_user_id as uid, SUM(expenses.amount) as v')->pluck('v', 'uid');

        $budgetByUser = Deal::won()->whereNotNull('responsible_user_id')
            ->groupBy('responsible_user_id')->selectRaw('responsible_user_id as uid, COALESCE(SUM(budget),0) as v')->pluck('v', 'uid');

        $closedByUser = Deal::won()->whereNotNull('responsible_user_id')
            ->groupBy('responsible_user_id')->selectRaw('responsible_user_id as uid, count(*) as c')->pluck('c', 'uid');
        $totalByUser = Deal::whereNotNull('responsible_user_id')
            ->groupBy('responsible_user_id')->selectRaw('responsible_user_id as uid, count(*) as c')->pluck('c', 'uid');

        $uids = collect($incomeByUser->keys())
            ->merge($expenseByUser->keys())->merge($budgetByUser->keys())->merge($totalByUser->keys())
            ->unique()->filter()->values();

        $people = User::whereIn('id', $uids)->get(['id', 'name', 'avatar'])->keyBy('id');
        // Drop orphaned responsible ids (deleted users) so only real employees show.
        $uids = $uids->filter(fn ($id) => $people->has($id))->values();

        return $uids->map(function ($uid) use ($incomeByUser, $expenseByUser, $budgetByUser, $closedByUser, $totalByUser, $people, $rate, $taxRate) {
            $income = (float) ($incomeByUser[$uid] ?? 0);
            $expense = (float) ($expenseByUser[$uid] ?? 0);
            $budget = (float) ($budgetByUser[$uid] ?? 0);
            $tax = round($budget * $taxRate, 2);
            // Remainder = deal sum − tax − expenses. Employee bonus (ЗП) = rate% of it;
            // the company keeps the rest as its net profit.
            $remainder = round($budget - $tax - $expense, 2);
            $bonus = $remainder > 0 ? round($remainder * $rate, 2) : 0.0;
            $company = round($remainder - $bonus, 2);
            $margin = $budget > 0 ? round($company / $budget * 100, 1) : 0.0;

            return [
                'uid' => (int) $uid,
                'user' => $people[$uid]->name ?? '—',
                'avatar' => $people[$uid]->avatar ?? null,
                'deals' => (int) ($totalByUser[$uid] ?? 0),
                'closed' => (int) ($closedByUser[$uid] ?? 0),
                'income' => $income,
                'expense' => $expense,
                'budget' => $budget,
                'tax' => $tax,
                'remainder' => $remainder,
                'bonus' => $bonus,
                'company' => $company,
                'net' => $company,
                'margin' => $margin,
            ];
        })->values();
    }
}
