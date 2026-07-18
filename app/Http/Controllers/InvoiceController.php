<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvoiceRequest;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use App\Services\InvoiceNumberService;
use App\Services\PayrollService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    // Менеджер работает только со своими сделками — счета чужих сделок недоступны.
    private function assertOwnership(User $user, ?Model $entity): void
    {
        // Изоляция фирм: счета чужой компании (BAIA/ASU) недоступны никому,
        // кто к этой компании не привязан, — включая финансиста и директора.
        $companyId = $entity instanceof Project ? $entity->deal?->company_id : $entity?->company_id;
        abort_unless($entity === null || $user->worksInCompany($companyId ? (int) $companyId : null), 403);

        if ($user->hasRole('manager') && ! $user->hasAnyRole(['admin', 'director', 'financist'])) {
            abort_unless($entity && $entity->responsible_user_id === $user->id, 403);
        }
    }

    private function resolve(?string $type, ?int $id): ?Model
    {
        if (! $id) {
            return null;
        }

        return $type === 'project' ? Project::find($id) : Deal::find($id);
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Invoice::class);

        // Finance page is leadership-only; managers/workshop staff handle money inside deal cards.
        abort_unless($request->user()->hasAnyRole(['admin', 'director', 'financist']), 403);

        // Финансы разделены по фирмам: счёт принадлежит компании своей сделки
        // (счета цеховых заказов идут через сделку заказа).
        $invBase = Invoice::query()
            ->when(\App\Support\CurrentCompany::id(), fn ($q, $c) => $q->where(fn ($w) => $w
                ->where(fn ($d) => $d->where('invoiceable_type', 'deal')
                    ->whereIn('invoiceable_id', \App\Models\Deal::where('company_id', $c)->select('id')))
                ->orWhere(fn ($p) => $p->where('invoiceable_type', 'project')
                    ->whereIn('invoiceable_id', \App\Models\Project::whereHas('deal', fn ($d) => $d->where('company_id', $c))->select('id')))));

        $invoices = (clone $invBase)
            ->with('client:id,name')
            ->withSum('payments as payments_sum_amount', 'amount')
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where('number', 'like', "%{$s}%"))
            ->when($request->string('status')->toString(), fn ($q, $st) => $q->where('status', $st))
            ->latest()->paginate(20)->withQueryString();

        // Дебиторка для финансиста: выставлено / оплачено / остаток к оплате.
        $invoiced = (float) (clone $invBase)->sum('amount');
        $invoicePaid = (float) \App\Models\Payment::whereIn('invoice_id', (clone $invBase)->select('id'))->sum('amount');
        $invoiceTotals = [
            'invoiced' => $invoiced,
            'paid' => $invoicePaid,
            'debt' => max(0, $invoiced - $invoicePaid),
        ];

        // ---- Раздел «Расходы»: материальные/прочие, нал/банк, статус ----
        $companyId = \App\Support\CurrentCompany::id();
        // Скоуп расходов текущей фирмы: по сделке/заказу + расходы КОМПАНИИ
        // (аренда/интернет/бензин… — без сделки, по company_id).
        $expScope = fn ($q) => $q->when($companyId, fn ($qq, $c) => $qq->where(fn ($w) => $w
            ->where(fn ($d) => $d->where('expenseable_type', 'deal')
                ->whereIn('expenseable_id', \App\Models\Deal::where('company_id', $c)->select('id')))
            ->orWhere(fn ($p) => $p->where('expenseable_type', 'project')
                ->whereIn('expenseable_id', \App\Models\Project::whereHas('deal', fn ($d) => $d->where('company_id', $c))->select('id')))
            ->orWhere('company_id', $c)));

        $expBase = \App\Models\Expense::query()->tap($expScope)
            // Период применяется и к сводке, и к таблице — «сколько нал/банк
            // за месяц» видно сразу, без ручного суммирования.
            ->when($request->string('exp_from')->toString(), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->string('exp_to')->toString(), fn ($q, $d) => $q->whereDate('date', '<=', $d));

        $confirmed = fn () => (clone $expBase)->where('status', 'confirmed');
        $expenseTotals = [
            'all' => (float) $confirmed()->sum('amount'),
            'all_count' => $confirmed()->count(),
            'material' => (float) $confirmed()->whereNotNull('material_id')->sum('amount'),
            'other' => (float) $confirmed()->whereNull('material_id')->sum('amount'),
            // Нал/банк — разбивка ПРОЧИХ расходов (материальные исключены явно:
            // с недавних пор у них тоже есть способ оплаты).
            'cash' => (float) $confirmed()->whereNull('material_id')->where('payment_method', 'cash')->sum('amount'),
            'bank' => (float) $confirmed()->whereNull('material_id')->where('payment_method', 'bank')->sum('amount'),
            'pending_sum' => (float) (clone $expBase)->where('status', 'pending')->sum('amount'),
            'pending_count' => (clone $expBase)->where('status', 'pending')->count(),
        ];

        $expenses = (clone $expBase)
            ->with(['expenseable', 'category:id,name', 'material:id,name,unit', 'responsible:id,name', 'confirmedBy:id,name'])
            ->when($request->string('exp_status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('exp_method')->toString(), fn ($q, $m) => $q->where('payment_method', $m))
            ->when($request->string('exp_kind')->toString(), fn ($q, $k) => $k === 'material' ? $q->whereNotNull('material_id') : $q->whereNull('material_id'))
            ->latest()->paginate(15, ['*'], 'exp_page')->withQueryString();

        // Canonical company finance — identical to Dashboard & Analytics (via PayrollService).
        $payroll = app(PayrollService::class);
        $fin = $payroll->companyTotals();
        $payrollRows = $payroll->perUser();
        $salaries = $payrollRows
            ->map(fn ($r) => ['user' => $r['user'], 'avatar' => $r['avatar'], 'bonus' => $r['bonus'], 'margin' => $r['margin'], 'income' => $r['income']])
            ->sortByDesc('bonus')->values();

        // ---- Сводка компании (эскиз бухгалтерии): Доход − ВСЕ расходы = Чистая прибыль ----
        // Доход = все фактические поступления по счетам компании. Сводка — за всё
        // время (без exp_from/exp_to, они только для таблицы/плиток раздела).
        $confirmedNoPeriod = fn () => \App\Models\Expense::query()->tap($expScope)->where('status', 'confirmed');
        $byCategory = $confirmedNoPeriod()->whereNotNull('category_id')
            ->groupBy('category_id')->selectRaw('category_id, sum(amount) s')->pluck('s', 'category_id');
        // Для селекта формы — только активные; разбивка же строится по ФАКТУ
        // расходов (иначе деактивация категории «теряла» бы её суммы из итога).
        $categories = \App\Models\ExpenseCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $catNames = \App\Models\ExpenseCategory::whereIn('id', $byCategory->keys())->pluck('name', 'id');
        $categoryRows = $byCategory
            ->map(fn ($sum, $id) => ['name' => $catNames[$id] ?? '—', 'sum' => (float) $sum])
            ->sortByDesc('sum')->values();
        // Расходы по сделкам/цеху (закуп + прочие без категории).
        $dealExpenses = (float) $confirmedNoPeriod()->whereNull('category_id')->sum('amount');
        $payrollTotal = round((float) $payrollRows->sum('payout'), 2); // оклады + бонусы
        $expensesTotal = round($categoryRows->sum('sum') + $dealExpenses + $payrollTotal + $fin['tax'], 2);

        // Остатки касса/банк: (платежи по счетам + ручные поступления) минус
        // расходы — всё по способу оплаты (нал/банк).
        $payByMethod = \App\Models\Payment::whereIn('invoice_id', (clone $invBase)->select('id'))
            ->groupBy('payment_method')->selectRaw('payment_method m, sum(amount) s')->pluck('s', 'm');
        $expCash = (float) $confirmedNoPeriod()->where('payment_method', 'cash')->sum('amount');
        $expBank = (float) $confirmedNoPeriod()->where('payment_method', '!=', 'cash')->whereNotNull('payment_method')->sum('amount');
        $payCash = (float) ($payByMethod['cash'] ?? 0);
        $payBank = (float) collect($payByMethod)->except('cash')->sum();

        // Задолженности: дебиторка вручную (плюс автоматическая по счетам) и кредиторка.
        $debtBase = \App\Models\Debt::query()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))
            ->with('creator:id,name')->latest('date')->latest('id');
        $receivableDebts = (clone $debtBase)->where('type', 'receivable')->get();
        $payableDebts = (clone $debtBase)->where('type', 'payable')->get();

        // «Доход» — итог Сводного отчёта (остаток − бонус по каждой сделке).
        $dealsIncome = app(\App\Services\FinanceService::class)->dealsIncome($companyId ?: null);

        // Поступления денег (вводит финансист): нал/банк, откуда, дата, комментарий.
        $receiptBase = \App\Models\CashReceipt::query()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c));
        $receiptCash = (float) (clone $receiptBase)->where('method', 'cash')->sum('amount');
        $receiptBank = (float) (clone $receiptBase)->where('method', 'bank')->sum('amount');
        $receipts = (clone $receiptBase)->with('creator:id,name')
            ->latest('date')->latest('id')->limit(30)->get();
        $incomeTotal = round($invoicePaid + $receiptCash + $receiptBank, 2);

        return Inertia::render('Finance/Index', [
            'invoices' => $invoices,
            'invoiceTotals' => $invoiceTotals,
            'expenses' => $expenses,
            'expenseTotals' => $expenseTotals,
            'filters' => $request->only('search', 'status', 'exp_status', 'exp_method', 'exp_kind', 'exp_from', 'exp_to'),
            'salaries' => $salaries,
            'categories' => $categories,
            'canManage' => $request->user()->hasAnyRole(['admin', 'financist']),
            'receipts' => $receipts,
            'debts' => ['receivables' => $receivableDebts, 'payables' => $payableDebts],
            'summary' => [
                'contracts' => (float) \App\Models\Deal::forCurrentCompany()->where('status', '!=', 'cancelled')->sum('budget'),
                'receivables' => $invoiceTotals['debt'],
                'receivablesManual' => (float) $receivableDebts->sum('amount'),
                'receivablesTotal' => round($invoiceTotals['debt'] + $receivableDebts->sum('amount'), 2),
                'payables' => (float) $payableDebts->sum('amount'),
                'dealsIncome' => $dealsIncome,
                'cash' => round($payCash + $receiptCash - $expCash, 2),
                'bank' => round($payBank + $receiptBank - $expBank, 2),
                'income' => $incomeTotal,
                'incomeInvoices' => $invoicePaid,
                'incomeManual' => round($receiptCash + $receiptBank, 2),
                'categories' => $categoryRows,
                'dealExpenses' => $dealExpenses,
                'payroll' => $payrollTotal,
                'tax' => $fin['tax'],
                'expensesTotal' => $expensesTotal,
                'net' => round($incomeTotal - $expensesTotal, 2),
            ],
            'totals' => [
                'budget' => $fin['budget'],
                'paid' => $fin['income'],
                'expenses' => $fin['expense'],
                'salaries' => $fin['bonus'],
                'tax' => $fin['tax'],
                'net' => $fin['company'],
            ],
        ]);
    }

    public function store(InvoiceRequest $request, InvoiceNumberService $numbers): RedirectResponse
    {
        $this->authorize('create', Invoice::class);
        $this->assertOwnership($request->user(), $this->resolve($request->input('invoiceable_type', 'deal'), (int) $request->input('invoiceable_id')));

        $data = $request->validated();
        $data['number'] = $numbers->generate();
        $data['status'] ??= 'draft';
        $data['issue_date'] ??= now()->toDateString();

        Invoice::create($data);

        return back()->with('success', 'Счёт создан.');
    }

    public function update(InvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('update', $invoice);
        $this->assertOwnership($request->user(), $invoice->invoiceable);

        $data = $request->validated();
        // Полиморфную привязку не меняем (иначе счёт увели бы на чужую сделку).
        unset($data['invoiceable_type'], $data['invoiceable_id']);
        // paid/partially_paid/overdue — производные от платежей (FinanceService::
        // recalcInvoiceStatus). Вручную допустимы только draft/sent/cancelled,
        // иначе можно выставить «оплачено» без единого платежа.
        if (isset($data['status']) && ! in_array($data['status'], ['draft', 'sent', 'cancelled'], true)) {
            unset($data['status']);
        }
        $invoice->update($data);

        return back()->with('success', 'Счёт обновлён.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $this->authorize('delete', $invoice);
        $this->assertOwnership(request()->user(), $invoice->invoiceable);
        $invoice->delete();
        \App\Support\FinanceAudit::notifyDeleted('Счёт '.$invoice->number.' на '.number_format((float) $invoice->amount, 0, '.', ' ').' ₸');

        return back()->with('success', 'Счёт удалён.');
    }
}
