<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\FinanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function store(PaymentRequest $request, FinanceService $finance): RedirectResponse
    {
        $this->authorize('create', Invoice::class);

        DB::transaction(function () use ($request, $finance) {
            $payment = Payment::create($request->validated());
            $finance->recalcInvoiceStatus($payment->invoice);
        });

        return back()->with('success', 'Платёж добавлен.');
    }

    public function destroy(Payment $payment, FinanceService $finance): RedirectResponse
    {
        $this->authorize('delete', $payment->invoice);

        DB::transaction(function () use ($payment, $finance) {
            $invoice = $payment->invoice;
            $payment->delete();
            $finance->recalcInvoiceStatus($invoice);
        });

        return back()->with('success', 'Платёж удалён.');
    }
}
