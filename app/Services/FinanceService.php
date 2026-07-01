<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class FinanceService
{
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

        return compact('income', 'invoiced', 'expense', 'profit', 'margin');
    }
}
