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

        $invoices = Invoice::query()
            ->with('client:id,name')
            ->withSum('payments as payments_sum_amount', 'amount')
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where('number', 'like', "%{$s}%"))
            ->when($request->string('status')->toString(), fn ($q, $st) => $q->where('status', $st))
            ->latest()->paginate(20)->withQueryString();

        // Canonical company finance — identical to Dashboard & Analytics (via PayrollService).
        $payroll = app(PayrollService::class);
        $fin = $payroll->companyTotals();
        $salaries = $payroll->perUser()
            ->map(fn ($r) => ['user' => $r['user'], 'avatar' => $r['avatar'], 'bonus' => $r['bonus'], 'margin' => $r['margin'], 'income' => $r['income']])
            ->sortByDesc('bonus')->values();

        return Inertia::render('Finance/Index', [
            'invoices' => $invoices,
            'filters' => $request->only('search', 'status'),
            'salaries' => $salaries,
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
        $invoice->update($request->validated());

        return back()->with('success', 'Счёт обновлён.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $this->authorize('delete', $invoice);
        $this->assertOwnership(request()->user(), $invoice->invoiceable);
        $invoice->delete();

        return back()->with('success', 'Счёт удалён.');
    }
}
