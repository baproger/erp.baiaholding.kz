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
            ->when($request->string('search')->toString(), fn ($q, $s) => $q
                ->where('name', 'like', "%{$s}%")->orWhere('number', 'like', "%{$s}%"))
            ->when($request->string('responsible')->toString(), fn ($q, $r) => $q->where('responsible_user_id', $r));

        $stages = DealStage::with('translations')->where('is_active', true)->orderBy('order')->get()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName(), 'color' => $s->color, 'order' => $s->order, 'is_won' => $s->is_won]);

        $deals = $view === 'list'
            ? (clone $base)->latest()->paginate(20)->withQueryString()
            : (clone $base)->latest()->get();

        return Inertia::render('Deals/Index', [
            'deals' => $deals,
            'stages' => $stages,
            'view' => $view,
            'filters' => $request->only('search', 'responsible'),
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
            'client', 'responsible:id,name', 'department:id,name',
            'stage', 'project:id,number,name,status',
            'tasks' => fn ($q) => $q->with('assignee:id,name')->latest(),
            'invoices' => fn ($q) => $q->withSum('payments as payments_sum_amount', 'amount')
                ->with('payments')->latest(),
            'expenses' => fn ($q) => $q->with('responsible:id,name')->latest(),
            'documents' => fn ($q) => $q->where('is_active', true)->with('user:id,name')->latest(),
            'comments' => fn ($q) => $q->with('user:id,name')->latest(),
        ]);

        return Inertia::render('Deals/Show', [
            'deal' => $deal,
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stages' => DealStage::with('translations')->where('is_active', true)->orderBy('order')->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName(), 'color' => $s->color, 'order' => $s->order, 'is_won' => $s->is_won, 'checklist' => $s->checklist]),
            'finance' => $finance->summaryFor($deal),
            'history' => \App\Models\AuditLog::where('table_name', 'deals')->where('record_id', $deal->id)->with('user:id,name')->latest()->limit(100)->get(),
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

    public function advance(Request $request, Deal $deal, StageTransitionService $transitions): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $deal);
        $next = DealStage::where('is_active', true)->where('order', '>', optional($deal->stage)->order ?? 0)->orderBy('order')->first();
        if ($next) {
            $transitions->moveToStage($deal, $next);
            return back()->with('success', 'Сделка переведена на этап «'.$next->name.'».');
        }
        return back()->with('error', 'Это последний этап.');
    }

    public function sendToWorkshop(Request $request, Deal $deal, \App\Services\ProjectService $projects): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $deal);
        if ($deal->project) {
            return back()->with('error', 'Заказ уже отправлен в цех.');
        }
        $project = $projects->createFromDeal($deal);
        $deal->update(['status' => 'closed', 'closed_at' => now()]);
        return back()->with('success', 'Отправлено в цех: '.$project->number.'.');
    }

    public function updateResponsible(Request $request, Deal $deal): RedirectResponse
    {
        // Any authenticated user may (re)assign the responsible person.
        $validated = $request->validate(['responsible_user_id' => ['nullable', 'exists:users,id']]);
        $deal->update(['responsible_user_id' => $validated['responsible_user_id'] ?: null]);

        return back()->with('success', 'Ответственный изменён.');
    }
}
