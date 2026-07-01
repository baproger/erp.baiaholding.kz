<?php

namespace App\Http\Controllers;

use App\Http\Requests\DealRequest;
use App\Models\Client;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Department;
use App\Models\User;
use App\Services\DealNumberService;
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
            ->with(['client:id,name', 'responsible:id,name', 'stage:id,name,color,order'])
            ->when($request->string('search')->toString(), fn ($q, $s) => $q
                ->where('name', 'like', "%{$s}%")->orWhere('number', 'like', "%{$s}%"))
            ->when($request->string('responsible')->toString(), fn ($q, $r) => $q->where('responsible_user_id', $r));

        $stages = DealStage::where('is_active', true)->orderBy('order')
            ->get(['id', 'name', 'color', 'order', 'is_won']);

        if ($view === 'list') {
            $deals = (clone $base)->latest()->paginate(20)->withQueryString();
        } else {
            // Kanban: all matching deals grouped client-side by stage.
            $deals = (clone $base)->latest()->get();
        }

        return Inertia::render('Deals/Index', [
            'deals' => $deals,
            'stages' => $stages,
            'view' => $view,
            'filters' => $request->only('search', 'responsible'),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'can' => [
                'create' => $request->user()->can('create', Deal::class),
            ],
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

    public function show(Deal $deal): Response
    {
        $this->authorize('view', $deal);

        $deal->load([
            'client', 'responsible:id,name', 'department:id,name',
            'stage', 'project:id,number,name,status',
            'tasks' => fn ($q) => $q->with('assignee:id,name')->latest(),
        ]);

        return Inertia::render('Deals/Show', [
            'deal' => $deal,
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stages' => DealStage::where('is_active', true)->orderBy('order')
                ->get(['id', 'name', 'color', 'order', 'is_won', 'checklist']),
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

        $validated = $request->validate([
            'deal_stage_id' => ['required', 'exists:deal_stages,id'],
        ]);

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
}
