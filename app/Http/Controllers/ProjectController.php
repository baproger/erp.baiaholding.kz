<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use App\Services\FinanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $view = $request->string('view', 'kanban')->toString();

        $base = Project::query()
            ->with(['client:id,name', 'responsible:id,name', 'stage:id,name,color,order'])
            ->when($request->string('search')->toString(), fn ($q, $s) => $q
                ->where('name', 'like', "%{$s}%")->orWhere('number', 'like', "%{$s}%"));

        $stages = ProjectStage::with('translations')->where('is_active', true)->orderBy('order')->get()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName(), 'color' => $s->color, 'order' => $s->order, 'is_completed' => $s->is_completed]);

        $projects = $view === 'list'
            ? (clone $base)->latest()->paginate(20)->withQueryString()
            : (clone $base)->latest()->get();

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'stages' => $stages,
            'view' => $view,
            'filters' => $request->only('search'),
        ]);
    }

    public function show(Project $project, FinanceService $finance): Response
    {
        $this->authorize('view', $project);

        $project->load([
            'client', 'responsible:id,name', 'department:id,name',
            'stage', 'deal:id,number,name',
            'tasks' => fn ($q) => $q->with('assignee:id,name')->latest(),
            'invoices' => fn ($q) => $q->withSum('payments as payments_sum_amount', 'amount')
                ->with('payments')->latest(),
            'expenses' => fn ($q) => $q->with('responsible:id,name')->latest(),
            'documents' => fn ($q) => $q->where('is_active', true)->with('user:id,name')->latest(),
            'comments' => fn ($q) => $q->with('user:id,name')->latest(),
        ]);

        return Inertia::render('Projects/Show', [
            'project' => $project,
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stages' => ProjectStage::with('translations')->where('is_active', true)->orderBy('order')->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName(), 'color' => $s->color, 'order' => $s->order, 'is_completed' => $s->is_completed]),
            'finance' => $finance->summaryFor($project),
        ]);
    }

    public function updateStage(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate(['project_stage_id' => ['required', 'exists:project_stages,id']]);
        $stage = ProjectStage::findOrFail($validated['project_stage_id']);
        $project->project_stage_id = $stage->id;

        if ($stage->is_completed) {
            $project->status = 'completed';
            $project->completed_at = now();
        }
        $project->save();

        return back()->with('success', 'Этап проекта обновлён.');
    }
}
