<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvoiceRequest;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Services\InvoiceNumberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Invoice::class);

        $user = $request->user();
        // Managers only see finance tied to their own deals/projects.
        $isManager = $user->hasRole('manager') && ! $user->hasAnyRole(['admin', 'director', 'financist']);

        $invoiceScope = function ($q) use ($isManager, $user) {
            if ($isManager) {
                $q->whereHasMorph('invoiceable', [Deal::class, Project::class], fn ($m) => $m->where('responsible_user_id', $user->id));
            }
        };
        $expenseScope = function ($q) use ($isManager, $user) {
            if ($isManager) {
                $q->whereHasMorph('expenseable', [Deal::class, Project::class], fn ($m) => $m->where('responsible_user_id', $user->id));
            }
        };

        $invoices = Invoice::query()
            ->where($invoiceScope)
            ->with('client:id,name')
            ->withSum('payments as payments_sum_amount', 'amount')
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where('number', 'like', "%{$s}%"))
            ->when($request->string('status')->toString(), fn ($q, $st) => $q->where('status', $st))
            ->latest()->paginate(20)->withQueryString();

        $invoiceIds = Invoice::query()->where($invoiceScope)->pluck('id');

        return Inertia::render('Finance/Index', [
            'invoices' => $invoices,
            'filters' => $request->only('search', 'status'),
            'totals' => [
                'invoiced' => (float) Invoice::query()->where($invoiceScope)->whereNotIn('status', ['cancelled', 'draft'])->sum('amount'),
                'paid' => (float) Payment::whereIn('invoice_id', $invoiceIds)->sum('amount'),
                'expenses' => (float) Expense::where('status', 'confirmed')->where($expenseScope)->sum('amount'),
            ],
        ]);
    }

    public function store(InvoiceRequest $request, InvoiceNumberService $numbers): RedirectResponse
    {
        $this->authorize('create', Invoice::class);

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
        $invoice->update($request->validated());

        return back()->with('success', 'Счёт обновлён.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $this->authorize('delete', $invoice);
        $invoice->delete();

        return back()->with('success', 'Счёт удалён.');
    }
}
