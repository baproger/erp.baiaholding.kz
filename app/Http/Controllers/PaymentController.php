<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\FinanceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    /** A plain manager may only touch finance tied to their own deal/project. */
    private function assertOwnership(User $user, ?Model $entity): void
    {
        // Изоляция фирм: финансы чужой компании (BAIA/ASU) недоступны никому,
        // кто к этой компании не привязан, — включая финансиста и директора.
        $companyId = $entity instanceof \App\Models\Project ? $entity->deal?->company_id : $entity?->company_id;
        abort_unless($entity === null || $user->worksInCompany($companyId ? (int) $companyId : null), 403);

        if ($user->hasRole('manager') && ! $user->hasAnyRole(['admin', 'director', 'financist'])) {
            abort_unless($entity && $entity->responsible_user_id === $user->id, 403);
        }
    }

    public function store(PaymentRequest $request, FinanceService $finance): RedirectResponse
    {
        $this->authorize('create', Invoice::class);
        $invoice = Invoice::findOrFail($request->integer('invoice_id'));
        $this->assertOwnership($request->user(), $invoice->invoiceable);

        DB::transaction(function () use ($request, $finance) {
            // Переплата запрещена: платёж не может превысить остаток по счёту.
            // Это же отсекает случайный ПОВТОРНЫЙ платёж (двойная отправка
            // формы) — по оплаченному счёту второй платёж не пройдёт.
            // Блокировка строки — от гонки двух одновременных отправок.
            $invoice = Invoice::whereKey($request->integer('invoice_id'))->lockForUpdate()->firstOrFail();
            $remaining = round((float) $invoice->amount - (float) $invoice->payments()->sum('amount'), 2);
            if ((float) $request->validated()['amount'] > $remaining + 0.005) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'amount' => $remaining <= 0
                        ? 'Счёт уже оплачен полностью — возможно, платёж отправлен повторно.'
                        : 'Платёж больше остатка по счёту: осталось '.number_format($remaining, 0, '.', ' ').' ₸.',
                ]);
            }
            $payment = Payment::create($request->validated());
            $finance->recalcInvoiceStatus($payment->invoice);
        });

        return back()->with('success', 'Платёж добавлен.');
    }

    public function destroy(Payment $payment, FinanceService $finance): RedirectResponse
    {
        $this->authorize('delete', $payment->invoice);
        $this->assertOwnership(request()->user(), $payment->invoice->invoiceable);

        DB::transaction(function () use ($payment, $finance) {
            $invoice = $payment->invoice;
            $payment->delete();
            $finance->recalcInvoiceStatus($invoice);
        });
        \App\Support\FinanceAudit::notifyDeleted(
            'Платёж на '.number_format((float) $payment->amount, 0, '.', ' ').' ₸ по счёту '.$payment->invoice->number,
            \App\Support\FinanceAudit::linkTo($payment->invoice->invoiceable)
        );

        return back()->with('success', 'Платёж удалён.');
    }
}
