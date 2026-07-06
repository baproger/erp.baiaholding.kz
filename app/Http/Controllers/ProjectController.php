<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use App\Services\FinanceService;
use App\Support\AuditFormatter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    private function canSeeMoney(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'director', 'financist', 'manager']);
    }

    private function scope($query, Request $request)
    {
        $user = $request->user();
        if ($user->hasRole('manager') && ! $user->hasAnyRole(['admin', 'director', 'financist'])) {
            $uid = $user->id;

            return $query->where(fn ($w) => $w
                ->where('responsible_user_id', $uid)
                ->orWhereHas('deal', fn ($d) => $d->where('responsible_user_id', $uid)));
        }

        return $query;
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $view = $request->string('view', 'kanban')->toString();

        $base = Project::query()
            ->where('status', '!=', 'completed')
            ->with(['client:id,name', 'responsible:id,name,avatar', 'stage:id,name,color,order', 'deal:id,number,company_name'])
            ->withCount(['tasks as overdue_count' => fn ($q) => $q->where('status', '!=', 'done')->whereNotNull('due_date')->where('due_date', '<', now())]);
        $this->scope($base, $request);
        $base->when($request->string('search')->toString(), fn ($q, $s) => $q
            ->where('name', 'like', "%{$s}%")->orWhere('number', 'like', "%{$s}%"));

        $stages = ProjectStage::with('translations')->where('is_active', true)->orderBy('order')->get()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName(), 'color' => $s->color, 'order' => $s->order, 'is_completed' => $s->is_completed]);

        $projects = $view === 'list'
            ? (clone $base)->latest()->paginate(20)->withQueryString()
            : (clone $base)->latest()->get();

        // Цех не видит суммы — прячем budget из сериализуемой модели, а не только в UI.
        $canSeeMoney = $this->canSeeMoney($request);
        if (! $canSeeMoney) {
            ($projects instanceof \Illuminate\Pagination\AbstractPaginator ? $projects->getCollection() : $projects)
                ->transform(fn ($p) => $p->makeHidden('budget'));
        }

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'stages' => $stages,
            'view' => $view,
            'filters' => $request->only('search'),
            'canSeeMoney' => $canSeeMoney,
        ]);
    }

    public function show(Project $project, FinanceService $finance, Request $request): Response
    {
        $this->authorize('view', $project);

        $project->load([
            'client', 'responsible:id,name,avatar', 'department:id,name',
            'stage', 'deal:id,number,name',
            'tasks' => fn ($q) => $q->with('assignee:id,name')->latest(),
            'documents' => fn ($q) => $q->where('is_active', true)->with('user:id,name')->latest(),
            'comments' => fn ($q) => $q->with('user:id,name')->latest(),
        ]);

        $canSeeMoney = $this->canSeeMoney($request);
        if (! $canSeeMoney) {
            $project->makeHidden('budget');
        }

        // Finance & history follow the originating deal so the whole lifecycle
        // (sale → production) is one continuous picture for the manager.
        $source = $project->deal_id
            ? Deal::with([
                'invoices' => fn ($q) => $q->withSum('payments as payments_sum_amount', 'amount')->with('payments')->latest(),
                'expenses' => fn ($q) => $q->with('responsible:id,name,avatar')->latest(),
            ])->find($project->deal_id)
            : null;
        $source ??= $project->load([
            'invoices' => fn ($q) => $q->withSum('payments as payments_sum_amount', 'amount')->with('payments')->latest(),
            'expenses' => fn ($q) => $q->with('responsible:id,name,avatar')->latest(),
        ]);

        // Merge audit history of the project and its deal.
        $history = AuditFormatter::humanize(
            AuditLog::query()
                ->where(function ($q) use ($project) {
                    $q->where(fn ($w) => $w->where('table_name', 'projects')->where('record_id', $project->id));
                    if ($project->deal_id) {
                        $q->orWhere(fn ($w) => $w->where('table_name', 'deals')->where('record_id', $project->deal_id));
                    }
                })
                ->with('user:id,name')->latest()->limit(150)->get(),
            [
                'project_stage_id' => ProjectStage::pluck('name', 'id'),
                'deal_stage_id' => DealStage::pluck('name', 'id'),
                'responsible_user_id' => User::pluck('name', 'id'),
            ]
        );

        // Цех не должен видеть изменения суммы сделки в истории.
        if (! $canSeeMoney) {
            $history = $history->reject(fn ($log) => $log->field_name === 'budget')->values();
        }

        return Inertia::render('Projects/Show', [
            'project' => $project,
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stages' => ProjectStage::with('translations')->where('is_active', true)->orderBy('order')->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName(), 'color' => $s->color, 'order' => $s->order, 'is_completed' => $s->is_completed]),
            'finance' => $canSeeMoney ? $finance->summaryFor($source) : null,
            'financeEntityType' => $project->deal_id ? 'deal' : 'project',
            'financeEntityId' => $source->id,
            'financeInvoices' => $canSeeMoney ? $source->invoices : [],
            'financeExpenses' => $canSeeMoney ? $source->expenses : [],
            'canSeeMoney' => $canSeeMoney,
            'history' => $history,
        ]);
    }

    public function updateStage(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('view', $project);

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

    public function advance(Project $project): RedirectResponse
    {
        $this->authorize('view', $project);
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

    /**
     * Workshop "АКТ": from the last workshop stage («Отправка»), send the order
     * back to the Deals board at «Акт утверждение» and close the workshop project.
     */
    public function sendToAct(Project $project): RedirectResponse
    {
        $this->authorize('view', $project);

        $deal = $project->deal;
        if (! $deal) {
            return back()->with('error', 'У заказа нет исходной сделки.');
        }

        // «Акт утверждение» = 2nd-from-last active deal stage.
        $returnStage = DealStage::where('is_active', true)->orderBy('order')->get()->slice(-2, 1)->first();
        if (! $returnStage) {
            return back()->with('error', 'Не найден этап «Акт утверждение».');
        }

        $deal->update(['deal_stage_id' => $returnStage->id, 'status' => 'active', 'closed_at' => null]);
        $project->update(['status' => 'completed', 'completed_at' => now()]);

        return back()->with('success', 'Сделка отправлена на «Акт утверждение».');
    }
}
