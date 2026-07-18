<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class FinanceService
{
    /**
     * Остатки денег фирмы (касса/банк) = платежи по счетам + поступления
     * (cash_receipts) − подтверждённые расходы, всё по способу оплаты.
     * Та же математика, что в сводке на странице Финансы; показывается
     * бухгалтеру в форме расхода («доступно N»).
     */
    public function companyBalances(?int $companyId): array
    {
        $invIds = Invoice::query()
            ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w
                ->where(fn ($d) => $d->where('invoiceable_type', 'deal')
                    ->whereIn('invoiceable_id', \App\Models\Deal::where('company_id', $c)->select('id')))
                ->orWhere(fn ($p) => $p->where('invoiceable_type', 'project')
                    ->whereIn('invoiceable_id', \App\Models\Project::whereHas('deal', fn ($d) => $d->where('company_id', $c))->select('id')))))
            ->select('id');

        $payByMethod = Payment::whereIn('invoice_id', $invIds)
            ->groupBy('payment_method')->selectRaw('payment_method m, sum(amount) s')->pluck('s', 'm');

        $rec = \App\Models\CashReceipt::query()->when($companyId, fn ($q, $c) => $q->where('company_id', $c));
        $recCash = (float) (clone $rec)->where('method', 'cash')->sum('amount');
        $recBank = (float) (clone $rec)->where('method', 'bank')->sum('amount');

        $exp = \App\Models\Expense::query()->where('status', 'confirmed')
            ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w
                ->where(fn ($d) => $d->where('expenseable_type', 'deal')
                    ->whereIn('expenseable_id', \App\Models\Deal::where('company_id', $c)->select('id')))
                ->orWhere(fn ($p) => $p->where('expenseable_type', 'project')
                    ->whereIn('expenseable_id', \App\Models\Project::whereHas('deal', fn ($d) => $d->where('company_id', $c))->select('id')))
                ->orWhere('company_id', $c)));
        $expCash = (float) (clone $exp)->where('payment_method', 'cash')->sum('amount');
        $expBank = (float) (clone $exp)->where('payment_method', '!=', 'cash')->whereNotNull('payment_method')->sum('amount');

        return [
            'cash' => round((float) ($payByMethod['cash'] ?? 0) + $recCash - $expCash, 2),
            'bank' => round((float) collect($payByMethod)->except('cash')->sum() + $recBank - $expBank, 2),
        ];
    }

    /**
     * «Доход» как в итогах Сводного отчёта: по каждой сделке компании (кроме
     * отменённых) остаток − бонус менеджера, суммой. Та же формула, что в
     * ReportController — цифры на Финансах и в отчёте совпадают.
     */
    public function dealsIncome(?int $companyId): float
    {
        $taxRate = ((float) \App\Models\Setting::get('tax_percent', 3)) / 100;
        $deals = \App\Models\Deal::query()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))
            ->where('status', '!=', 'cancelled')
            ->get(['id', 'budget']);

        $expByDeal = \App\Models\Expense::where('status', 'confirmed')
            ->where('expenseable_type', 'deal')
            ->whereIn('expenseable_id', $deals->pluck('id'))
            ->groupBy('expenseable_id')->selectRaw('expenseable_id d, sum(amount) s')->pluck('s', 'd');

        return round($deals->sum(function ($d) use ($expByDeal, $taxRate) {
            $budget = (float) $d->budget;
            $tax = round($budget * $taxRate, 2);
            $remainder = round($budget - $tax - (float) ($expByDeal[$d->id] ?? 0), 2);

            return $remainder - \App\Services\PayrollService::marginBonus($budget, $remainder, $tax);
        }), 2);
    }

    /**
     * Recalculate an invoice's status from its payments. Atomic and lock-safe.
     */
    public function recalcInvoiceStatus(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (in_array($invoice->status, ['cancelled', 'draft'], true)) {
                return $invoice;
            }

            $paid = (float) $invoice->payments()->sum('amount');
            $amount = (float) $invoice->amount;

            if ($paid <= 0) {
                $invoice->status = 'sent';
            } elseif ($paid + 0.001 >= $amount) {
                $invoice->status = 'paid';
            } else {
                $invoice->status = 'partially_paid';
            }

            $invoice->save();

            return $invoice;
        });
    }

    /**
     * Compute financial summary for a deal/project.
     * income  = sum of all payments across the entity's invoices
     * expense = sum of confirmed expenses
     * margin  = (income - expense) / income * 100
     *
     * @return array{income: float, expense: float, profit: float, margin: float, invoiced: float}
     */
    public function summaryFor(Model $entity): array
    {
        $invoiceIds = $entity->invoices()->pluck('id');

        $income = (float) Payment::whereIn('invoice_id', $invoiceIds)->sum('amount');
        $invoiced = (float) $entity->invoices()->whereNotIn('status', ['cancelled'])->sum('amount');
        $expense = (float) $entity->expenses()->where('status', 'confirmed')->sum('amount');
        $profit = $income - $expense;
        $margin = $income > 0 ? round($profit / $income * 100, 2) : 0.0;

        // Planned figures use the deal/project budget (won sum) as expected revenue.
        $budget = (float) ($entity->budget ?? 0);
        $plannedProfit = $budget - $expense;
        $plannedMargin = $budget > 0 ? round($plannedProfit / $budget * 100, 2) : 0.0;
        // Markup = profit relative to costs; expenseRatio = costs share of income.
        $markup = $expense > 0 ? round($profit / $expense * 100, 2) : 0.0;
        $expenseRatio = $income > 0 ? round($expense / $income * 100, 2) : 0.0;

        return compact('income', 'invoiced', 'expense', 'profit', 'margin', 'budget', 'plannedProfit', 'plannedMargin', 'markup', 'expenseRatio');
    }
}
