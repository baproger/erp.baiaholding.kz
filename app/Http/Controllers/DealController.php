<?php

namespace App\Http\Controllers;

use App\Http\Requests\DealRequest;
use App\Models\Client;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Department;
use App\Models\User;
use App\Services\DealNumberService;
use App\Services\FinanceService;
use App\Services\StageTransitionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DealController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Deal::class);

        $view = $request->string('view', 'kanban')->toString();

        $base = Deal::query()
            ->with(['client:id,name', 'responsible:id,name,avatar', 'stage:id,name,color,order'])
            ->withCount('tasks')
            ->withCount(['tasks as overdue_count' => fn ($q) => $q->where('status', '!=', 'done')->whereNotNull('due_date')->where('due_date', '<', now())])
            ->where('status', '!=', 'closed')
            ->when(! $request->user()->hasAnyRole(['admin', 'director', 'financist']), fn ($q) => $q->where('responsible_user_id', $request->user()->id))
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('number', 'like', "%{$s}%")
                ->orWhere('lot_number', 'like', "%{$s}%")
                ->orWhere('bin', 'like', "%{$s}%")
                ->orWhere('company_name', 'like', "%{$s}%")))
            ->when($request->string('responsible')->toString(), fn ($q, $r) => $q->where('responsible_user_id', $r))
            ->when($request->date('date_from'), fn ($q, $d) => $q->whereDate('deadline', '>=', $d))
            ->when($request->date('date_to'), fn ($q, $d) => $q->whereDate('deadline', '<=', $d));

        $stages = DealStage::with('translations')->where('is_active', true)->orderBy('order')->get()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName(), 'color' => $s->color, 'order' => $s->order, 'is_won' => $s->is_won]);

        $deals = $view === 'list'
            ? (clone $base)->latest()->paginate(20)->withQueryString()
            : (clone $base)->latest()->get();

        return Inertia::render('Deals/Index', [
            'deals' => $deals,
            'stages' => $stages,
            'view' => $view,
            'filters' => $request->only('search', 'responsible', 'date_from', 'date_to'),
            'isLeadership' => $request->user()->hasAnyRole(['admin', 'director', 'financist']),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'can' => ['create' => $request->user()->can('create', Deal::class)],
        ]);
    }

    public function store(DealRequest $request, DealNumberService $numbers): RedirectResponse
    {
        $this->authorize('create', Deal::class);

        $data = $request->validated();
        $data['number'] = $numbers->generate();
        $data['deal_stage_id'] ??= DealStage::where('is_active', true)->orderBy('order')->value('id');
        $data['status'] = $data['status'] ?? 'active';

        Deal::create($data);

        return back()->with('success', 'Сделка создана.');
    }

    public function show(Deal $deal, FinanceService $finance): Response
    {
        $this->authorize('view', $deal);

        $deal->load([
            'client', 'responsible:id,name,avatar', 'department:id,name',
            'stage', 'project:id,number,name,status',
            'tasks' => fn ($q) => $q->with('assignee:id,name')->latest(),
            'invoices' => fn ($q) => $q->withSum('payments as payments_sum_amount', 'amount')
                ->with('payments')->latest(),
            'expenses' => fn ($q) => $q->with('responsible:id,name,avatar')->latest(),
            'documents' => fn ($q) => $q->where('is_active', true)->with('user:id,name')->latest(),
            'comments' => fn ($q) => $q->with('user:id,name')->latest(),
        ]);

        $dealChat = \App\Models\Chat::firstOrCreate(
            ['deal_id' => $deal->id],
            ['type' => 'group', 'name' => 'Чат ' . $deal->number, 'is_active' => true]
        );

        $taxRate = ((float) \App\Models\Setting::get('tax_percent', 3)) / 100;
        $bonusRate = ((float) \App\Models\Setting::get('bonus_percent', 10)) / 100;
        $confirmedExpense = (float) $deal->expenses->where('status', 'confirmed')->sum('amount');
        $dealBudget = (float) $deal->budget;
        $dealTax = round($dealBudget * $taxRate, 2);
        $dealRemainder = round($dealBudget - $dealTax - $confirmedExpense, 2);
        $dealBonus = $dealRemainder > 0 ? round($dealRemainder * $bonusRate, 2) : 0.0;

        return Inertia::render('Deals/Show', [
            'deal' => $deal,
            'profit' => [
                'budget' => $dealBudget,
                'tax' => $dealTax, 'taxRate' => $taxRate * 100,
                'expense' => $confirmedExpense,
                'remainder' => $dealRemainder,
                'bonus' => $dealBonus, 'bonusRate' => $bonusRate * 100,
                'company' => round($dealRemainder - $dealBonus, 2),
            ],
            'chatId' => $dealChat->id,
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stages' => DealStage::with('translations')->where('is_active', true)->orderBy('order')->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName(), 'color' => $s->color, 'order' => $s->order, 'is_won' => $s->is_won, 'checklist' => $s->checklist]),
            'finance' => $finance->summaryFor($deal),
            'history' => \App\Support\AuditFormatter::humanize(\App\Models\AuditLog::where('table_name', 'deals')->where('record_id', $deal->id)->with('user:id,name')->latest()->limit(100)->get(), ['deal_stage_id' => DealStage::pluck('name', 'id'), 'responsible_user_id' => User::pluck('name', 'id')]),
            'customFields' => app(\App\Services\CustomFieldService::class)->forEntity('deal', $deal->id),
            'can' => [
                'update' => request()->user()->can('update', $deal),
                'delete' => request()->user()->can('delete', $deal),
            ],
        ]);
    }

    public function update(DealRequest $request, Deal $deal): RedirectResponse
    {
        $this->authorize('update', $deal);
        $deal->update($request->validated());

        return back()->with('success', 'Сделка обновлена.');
    }

    public function updateStage(Request $request, Deal $deal, StageTransitionService $transitions): RedirectResponse
    {
        $this->authorize('update', $deal);

        $validated = $request->validate(['deal_stage_id' => ['required', 'exists:deal_stages,id']]);
        $target = DealStage::findOrFail($validated['deal_stage_id']);
        $transitions->moveToStage($deal, $target);

        return back()->with('success', 'Этап сделки обновлён.');
    }

    public function destroy(Deal $deal): RedirectResponse
    {
        $this->authorize('delete', $deal);
        $deal->delete();

        return back()->with('success', 'Сделка удалена.');
    }

    public function advance(Deal $deal, StageTransitionService $transitions): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $deal);
        $next = DealStage::where('is_active', true)->where('order', '>', optional($deal->stage)->order ?? 0)->orderBy('order')->first();
        if ($next) {
            $transitions->moveToStage($deal, $next);
            return back()->with('success', 'Сделка переведена на этап «'.$next->name.'».');
        }
        return back()->with('error', 'Это последний этап.');
    }

    public function sendToWorkshop(Deal $deal, \App\Services\ProjectService $projects): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $deal);
        if ($deal->project && $deal->project->status !== 'completed') {
            return back()->with('error', 'Заказ уже в цехе.');
        }
        $project = $projects->createFromDeal($deal);
        $deal->update(['status' => 'closed', 'closed_at' => now()]);
        return back()->with('success', 'Отправлено в цех: '.$project->number.'.');
    }

    public function updateResponsible(Request $request, Deal $deal): RedirectResponse
    {
        // Only the owner (or leadership) may (re)assign the responsible person.
        $this->authorize('update', $deal);
        $validated = $request->validate(['responsible_user_id' => ['nullable', 'exists:users,id']]);
        $deal->update(['responsible_user_id' => $validated['responsible_user_id'] ?: null]);

        return back()->with('success', 'Ответственный изменён.');
    }

    /**
     * Overdue deals: deadline is in the past and deal is still open.
     * Sorted so the most-overdue deal (earliest deadline) is on top.
     */
    public function overdue(Request $request): Response
    {
        $this->authorize('viewAny', Deal::class);

        $today = now()->startOfDay();

        $deals = Deal::query()
            ->with(['responsible:id,name,avatar', 'stage:id,name,color'])
            ->whereNotNull('deadline')
            ->whereDate('deadline', '<', $today)
            ->whereNotIn('status', ['closed', 'cancelled'])
            // A deal on the won stage («Оплата успешно») is already successful — not overdue.
            ->whereDoesntHave('stage', fn ($s) => $s->where('is_won', true))
            ->when(! $request->user()->hasAnyRole(['admin', 'director', 'financist']), fn ($q) => $q->where('responsible_user_id', $request->user()->id))
            ->orderBy('deadline')
            ->get()
            ->map(function ($d) use ($today) {
                $d->overdue_days = (int) \Illuminate\Support\Carbon::parse($d->deadline)->startOfDay()->diffInDays($today);

                return $d;
            });

        return Inertia::render('Deals/Overdue', ['deals' => $deals]);
    }

    /**
     * Look up an existing company by БИН (deals first, then clients).
     * Used by the create form to offer copying company data.
     */
    public function binLookup(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', Deal::class);
        $bin = trim($request->string('bin')->toString());
        if ($bin === '') {
            return response()->json(['match' => null, 'history' => []]);
        }

        $client = Client::where('inn', $bin)->first();
        $deal = Deal::where('bin', $bin)->whereNotNull('company_name')->latest()->first();

        $match = null;
        if ($client) {
            $match = ['company_name' => $client->name, 'bin' => $client->inn, 'phone' => $client->phone, 'address' => $client->address];
        } elseif ($deal) {
            $match = ['company_name' => $deal->company_name, 'bin' => $deal->bin, 'phone' => null, 'address' => null];
        }

        // All deals ever created for this БИН — the "История по БИН" list.
        $history = Deal::where('bin', $bin)->with('stage:id,name,color')
            ->latest()->limit(30)
            ->get(['id', 'number', 'company_name', 'client_name', 'budget', 'deadline', 'deal_stage_id', 'created_at'])
            ->map(fn ($d) => [
                'id' => $d->id, 'number' => $d->number,
                'company' => $d->company_name, 'client' => $d->client_name,
                'budget' => (float) $d->budget, 'deadline' => optional($d->deadline)->toDateString(),
                'stage' => optional($d->stage)->name, 'color' => optional($d->stage)->color,
                'created' => optional($d->created_at)->toDateString(),
            ]);

        return response()->json(['match' => $match, 'history' => $history]);
    }
}
