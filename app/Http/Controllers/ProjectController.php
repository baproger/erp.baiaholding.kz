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
    /** Roles that may see money in the Цех. */
    private function canSeeMoney(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'director', 'financist', 'manager']);
    }

    /** Non-privileged users only see Цех items tied to them. */
    private function scope($query, Request $request)
    {
        if ($request->user()->hasAnyRole(['admin', 'director', 'financist'])) {
            return $query;
        }
        $uid = $request->user()->id;

        return $query->where(fn ($w) => $w
            ->where('responsible_user_id', $uid)
            ->orWhereHas('deal', fn ($d) => $d->where('responsible_user_id', $uid))
            ->orWhereHas('tasks', fn ($t) => $t->where('assignee_id', $uid)));
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $view = $request->string('view', 'kanban')->toString();

        $base = Project::query()
            ->with(['client:id,name', 'responsible:id,name,avatar', 'stage:id,name,color,order'])
            ->withCount(['tasks as overdue_count' => fn ($q) => $q->where('status', '!=', 'done')->whereNotNull('due_date')->where('due_date', '<', now())]);
        $this->scope($base, $request);
        $base->when($request->string('search')->toString(), fn ($q, $s) => $q
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
            'canSeeMoney' => $this->canSeeMoney($request),
        ]);
    }

    public function show(Project $project, FinanceService $finance, Request $request): Response
    {
        $this->authorize('view', $project);

        $project->load([
            'client', 'responsible:id,name', 'department:id,name',
            'stage', 'deal:id,number,name',
            'tasks' => fn ($q) => $q->with('assignee:id,name')->latest(),
            'invoices' => fn ($q) => $q->withSum('payments as payments_sum_amount', 'amount')->with('payments')->latest(),
            'expenses' => fn ($q) => $q->with('responsible:id,name')->latest(),
            'documents' => fn ($q) => $q->where('is_active', true)->with('user:id,name')->latest(),
            'comments' => fn ($q) => $q->with('user:id,name')->latest(),
        ]);

        $canSeeMoney = $this->canSeeMoney($request);

        return Inertia::render('Projects/Show', [
            'project' => $project,
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stages' => ProjectStage::with('translations')->where('is_active', true)->orderBy('order')->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName(), 'color' => $s->color, 'order' => $s->order, 'is_completed' => $s->is_completed]),
            'finance' => $canSeeMoney ? $finance->summaryFor($project) : null,
            'canSeeMoney' => $canSeeMoney,
            'history' => \App\Models\AuditLog::where('table_name', 'projects')->where('record_id', $project->id)->with('user:id,name')->latest()->limit(100)->get(),
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

    public function advance(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        $next = ProjectStage::where('is_active', true)->where('order', '>', optional($project->stage)->order ?? 0)->orderBy('order')->first();
        if (! $next) {
            return back()->with('error', 'Это последний этап.');
        }
        $project->project_stage_id = $next->id;
        if ($next->is_completed) {
            $project->status = 'completed';
            $project->completed_at = now();
        }
        $project->save();

        return back()->with('success', 'Цех: этап «'.$next->name.'».');
    }
}
