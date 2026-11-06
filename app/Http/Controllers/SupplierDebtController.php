<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\SupplierDebt;
use App\Models\SupplierDebtPayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Финансы → «Долг сделкам» (ТЗ 02.11.2026, п.7): все долги поставщикам по
 * сделкам — из расходов «в долг», ручного ввода нет. Погашение (полное или
 * частичное) — реальная выплата из кассы/банка с чеком, делает бухгалтер/админ.
 */
class SupplierDebtController extends Controller
{
    private const VIEW_ROLES = ['admin', 'director', 'financist'];

    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasAnyRole(self::VIEW_ROLES), 403);
        $companyId = \App\Support\CurrentCompany::id() ?: null;
        $f = [
            'search' => trim($request->string('search')->toString()),
            'supplier' => trim($request->string('supplier')->toString()),
            'status' => in_array($request->string('status')->toString(), ['active', 'overdue', 'paid'], true) ? $request->string('status')->toString() : '',
            'from' => $request->date('from')?->toDateString(),
            'to' => $request->date('to')?->toDateString(),
        ];

        $debts = SupplierDebt::query()
            ->with(['deal:id,number,company_name,deleted_at', 'expense:id,file_path', 'payments' => fn ($q) => $q->with('creator:id,name')->orderBy('date')])
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))
            ->when($f['search'] !== '', fn ($q) => $q->whereHas('deal', fn ($d) => $d->where('number', 'like', "%{$f['search']}%")->orWhere('company_name', 'like', "%{$f['search']}%")))
            ->when($f['supplier'] !== '', fn ($q) => $q->where('supplier_name', 'like', "%{$f['supplier']}%"))
            ->when($f['status'] === 'paid', fn ($q) => $q->where('status', 'paid'))
            ->when($f['status'] === 'active', fn ($q) => $q->where('status', 'active')->whereDate('due_date', '>=', today()))
            ->when($f['status'] === 'overdue', fn ($q) => $q->where('status', 'active')->whereDate('due_date', '<', today()))
            ->when($f['from'], fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($f['to'], fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->orderBy('due_date')->limit(1000)->get();

        $rows = $debts->map(fn (SupplierDebt $d) => [
            'id' => $d->id,
            'deal' => $d->deal ? ['id' => $d->deal->id, 'number' => preg_replace('/#del\d*$/', '', (string) $d->deal->number), 'customer' => $d->deal->company_name, 'deleted' => $d->deal->trashed()] : null,
            'supplier' => $d->supplier_name,
            'contact' => $d->supplier_contact,
            'description' => $d->description,
            'amount' => (float) $d->amount,
            'paid' => (float) $d->paid,
            'remaining' => $d->remaining(),
            'due_date' => $d->due_date?->toDateString(),
            'state' => $d->state(),
            'has_document' => (bool) $d->document_path,
            'created_at' => $d->created_at?->toDateString(),
            'payments' => $d->payments->map(fn ($p) => [
                'id' => $p->id, 'amount' => (float) $p->amount, 'method' => $p->payment_method,
                'date' => $p->date?->toDateString(), 'by' => $p->creator?->name, 'note' => $p->note,
            ])->values(),
        ]);

        $groups = $rows->groupBy(fn ($r) => $r['deal']['id'] ?? 0)->map(fn ($list) => [
            'deal' => $list->first()['deal'],
            'rows' => $list->values(),
            'amount' => round($list->sum('amount'), 2),
            'paid' => round($list->sum('paid'), 2),
            'remaining' => round($list->sum('remaining'), 2),
        ])->sortByDesc('remaining')->values();

        return Inertia::render('Finance/SupplierDebts', [
            'groups' => $groups,
            'totals' => [
                'owed' => round($rows->where('state', '!=', 'paid')->sum('remaining'), 2),
                'overdue' => round($rows->where('state', 'overdue')->sum('remaining'), 2),
                'count' => $rows->where('state', '!=', 'paid')->count(),
            ],
            'filters' => $f,
            'canRepay' => $request->user()->hasAnyRole(['admin', 'financist']),
            // Удалить долг целиком (тестовые/ошибочные записи) — только супер-админ.
            'canDelete' => $request->user()->isSuperAdmin(),
            'balances' => app(\App\Services\FinanceService::class)->companyBalances($companyId),
        ]);
    }

    /** «Погасить» (п.3.6): выплата из кассы/банка с чеком — бухгалтер/админ. */
    public function repay(Request $request, SupplierDebt $debt): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'financist']), 403, 'Гасит долг бухгалтер или админ.');
        abort_unless($request->user()->worksInCompany($debt->company_id ? (int) $debt->company_id : null), 403);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'payment_method' => ['required', 'in:cash,bank'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:10240'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'amount.required' => 'Укажите сумму погашения.',
            'payment_method.required' => 'Выберите: наличные или банк.',
            'file.required' => 'Без чека погашение не проводится — прикрепите фото или PDF.',
            'date.before_or_equal' => 'Дата погашения не может быть в будущем.',
        ]);

        return DB::transaction(function () use ($request, $debt, $data) {
            $locked = SupplierDebt::whereKey($debt->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'paid') {
                return back()->with('error', 'Долг уже погашен.');
            }
            $amount = round((float) $data['amount'], 2);
            if ($amount > $locked->remaining() + 0.009) {
                return back()->withErrors(['amount' => 'Больше остатка долга ('.number_format($locked->remaining(), 0, '.', ' ').' ₸).']);
            }
            SupplierDebtPayment::create([
                'supplier_debt_id' => $locked->id, 'company_id' => $locked->company_id, 'amount' => $amount,
                'payment_method' => $data['payment_method'], 'date' => $data['date'],
                'receipt_path' => $request->file('file')->store('receipts', 'local'),
                'note' => $data['note'] ?? null, 'created_by' => $request->user()->id,
            ]);
            $paid = round((float) $locked->paid + $amount, 2);
            $done = $paid >= (float) $locked->amount - 0.009;
            $locked->update(['paid' => $paid, 'status' => $done ? 'paid' : 'active', 'paid_at' => $done ? now() : null]);
            \App\Support\ReportCache::bump();

            return back()->with('success', $done
                ? 'Долг «'.$locked->supplier_name.'» погашен полностью.'
                : 'Погашено '.number_format($amount, 0, '.', ' ').' ₸, остаток '.number_format($locked->remaining(), 0, '.', ' ').' ₸.');
        });
    }

    /**
     * Удалить долг (04.11.2026): только супер-админ — для тестовых и ошибочных
     * записей. Удаляются долг, его погашения (касса пересчитается сама) и расход
     * «в долг» в сделке; файлы чеков тоже. Руководству — уведомление, в Аудите — след.
     */
    public function destroy(Request $request, SupplierDebt $debt): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Удалить долг может только администратор.');

        $label = 'Долг «'.$debt->supplier_name.'» '.number_format((float) $debt->amount, 0, '.', ' ').' ₸'
            .($debt->deal ? ' по сделке '.$debt->deal->number : '')
            .((float) $debt->paid > 0 ? ' (с погашениями '.number_format((float) $debt->paid, 0, '.', ' ').' ₸)' : '');
        $files = collect([$debt->document_path])->merge($debt->payments->pluck('receipt_path'))->filter();

        DB::transaction(function () use ($debt) {
            $debt->payments()->delete();
            $expense = $debt->expense_id ? \App\Models\Expense::find($debt->expense_id) : null;
            $debt->delete();
            $expense?->delete();
        });
        $files->each(fn ($p) => Storage::disk('local')->delete($p));
        \App\Support\ReportCache::bump();
        \App\Support\FinanceAudit::notifyDeleted($label);

        return back()->with('success', $label.' удалён.');
    }

    /**
     * Удалить одно погашение (06.11.2026): ошибочная/тестовая выплата из кассы.
     * Только админ. Долг возвращается в «активен», касса пересчитается сама.
     */
    public function destroyPayment(Request $request, SupplierDebtPayment $payment): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403, 'Удалить погашение может только администратор.');

        $debt = $payment->debt;
        $label = 'Погашение '.number_format((float) $payment->amount, 0, '.', ' ').' ₸ по долгу «'.($debt?->supplier_name ?? '—').'»';
        DB::transaction(function () use ($payment, $debt) {
            $payment->delete();
            if ($debt) {
                $paid = round((float) $debt->payments()->sum('amount'), 2);
                $done = $paid >= (float) $debt->amount - 0.009;
                $debt->update(['paid' => $paid, 'status' => $done ? 'paid' : 'active', 'paid_at' => $done ? $debt->paid_at : null]);
            }
        });
        if ($payment->receipt_path) {
            Storage::disk('local')->delete($payment->receipt_path);
        }
        \App\Support\ReportCache::bump();
        \App\Support\FinanceAudit::notifyDeleted($label, $debt?->deal ? \App\Support\FinanceAudit::linkTo($debt->deal) : null);

        return back()->with('success', $label.' удалено.');
    }

    /** Документ долга (счёт/накладная) или чек погашения — руководству. */
    public function file(Request $request, SupplierDebt $debt, ?SupplierDebtPayment $payment = null): StreamedResponse
    {
        abort_unless($request->user()->hasAnyRole(self::VIEW_ROLES), 403);
        abort_unless($request->user()->worksInCompany($debt->company_id ? (int) $debt->company_id : null), 403);
        $path = $payment ? ($payment->supplier_debt_id === $debt->id ? $payment->receipt_path : null) : $debt->document_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }
}
