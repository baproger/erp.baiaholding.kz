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
            ->when(\App\Support\CurrentCompany::id(), fn ($q, $c) => $q->where('company_id', $c))
            ->when(! $request->user()->hasAnyRole(['admin', 'director', 'financist']), fn ($q) => $q->where('responsible_user_id', $request->user()->id))
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('number', 'like', "%{$s}%")
                ->orWhere('lot_number', 'like', "%{$s}%")
                ->orWhere('bin', 'like', "%{$s}%")
                ->orWhere('company_name', 'like', "%{$s}%")))
            ->when($request->string('responsible')->toString(), fn ($q, $r) => $q->where('responsible_user_id', $r))
            ->when($request->integer('stage'), fn ($q, $s) => $q->where('deal_stage_id', $s))
            ->when($request->date('date_from'), fn ($q, $d) => $q->whereDate('deadline', '>=', $d))
            ->when($request->date('date_to'), fn ($q, $d) => $q->whereDate('deadline', '<=', $d))
            ->when($request->date('contract_from'), fn ($q, $d) => $q->whereDate('contract_date', '>=', $d))
            ->when($request->date('contract_to'), fn ($q, $d) => $q->whereDate('contract_date', '<=', $d));

        // Воронка текущей компании; в режиме «Все компании» (id=0) — обе воронки,
        // колонки подписываются кодом фирмы.
        $companyId = \App\Support\CurrentCompany::id() ?: null;
        $companyCodes = \App\Models\Company::pluck('code', 'id');
        $stages = DealStage::with('translations')->where('is_active', true)
            ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w->where('company_id', $c)->orWhereNull('company_id')))
            ->orderBy('order')->orderBy('company_id')->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->translatedName().(! $companyId && $s->company_id ? ' · '.$companyCodes[$s->company_id] : ''),
                'color' => $s->color, 'order' => $s->order, 'is_won' => $s->is_won,
            ]);

        $deals = $view === 'list'
            ? (clone $base)->latest()->paginate(20)->withQueryString()
            : (clone $base)->latest()->get();

        return Inertia::render('Deals/Index', [
            'deals' => $deals,
            'stages' => $stages,
            'view' => $view,
            'filters' => $request->only('search', 'responsible', 'stage', 'date_from', 'date_to', 'contract_from', 'contract_to'),
            'isLeadership' => $request->user()->hasAnyRole(['admin', 'director', 'financist']),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'can' => [
                'create' => $request->user()->can('create', Deal::class),
                // Удаление (в т.ч. массовое) — только admin (DealPolicy::delete).
                'delete' => $request->user()->hasRole('admin'),
            ],
            'companies' => $request->user()->companies()->where('is_active', true)->orderBy('name')->get(['companies.id', 'name', 'code']),
            'currentCompanyId' => \App\Support\CurrentCompany::id(),
        ]);
    }

    public function store(DealRequest $request, DealNumberService $numbers): RedirectResponse
    {
        $this->authorize('create', Deal::class);

        $data = $request->validated();
        // Название сделки = название компании (поле «Название сделки» убрано из UI).
        $data['name'] = $data['company_name'];

        // Deal belongs to a firm (BAIA / ASU): the one picked in the form if the
        // user is a member of it, otherwise the current session company.
        $requested = (int) $request->input('company_id');
        $memberIds = $request->user()->companies()->where('is_active', true)->pluck('companies.id');
        $companyId = $memberIds->contains($requested) ? $requested : \App\Support\CurrentCompany::id();
        $company = $companyId ? \App\Models\Company::find($companyId) : null;

        $data['company_id'] = $company?->id;
        $data['number'] = $numbers->generate($company);
        // Первый этап ВОРОНКИ КОМПАНИИ сделки (у BAIA и ASU воронки свои).
        $data['deal_stage_id'] ??= DealStage::funnel($company?->id)->first()?->id;
        $data['status'] = $data['status'] ?? 'active';
        // Менеджер создаёт сделку только на себя — назначить ответственным другого нельзя.
        if (! $request->user()->hasAnyRole(['admin', 'director', 'financist'])) {
            $data['responsible_user_id'] = $request->user()->id;
        }

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
            'expenses' => fn ($q) => $q->with(['responsible:id,name,avatar', 'material:id,name,unit'])->latest(),
            'documents' => fn ($q) => $q->where('is_active', true)->with('user:id,name')->latest(),
            'comments' => fn ($q) => $q->with('user:id,name')->latest(),
        ]);

        $dealChat = \App\Models\Chat::firstOrCreate(
            ['deal_id' => $deal->id],
            ['type' => 'group', 'name' => 'Чат ' . $deal->number, 'is_active' => true]
        );

        $taxRate = ((float) \App\Models\Setting::get('tax_percent', 3)) / 100;
        $confirmedExpense = (float) $deal->expenses->where('status', 'confirmed')->sum('amount');
        $dealBudget = (float) $deal->budget;
        $dealTax = round($dealBudget * $taxRate, 2);
        $dealRemainder = round($dealBudget - $dealTax - $confirmedExpense, 2);
        // Ступенчатый бонус: ступень по марже ДО налога (как «Маржа» на карточке),
        // сам бонус — % от остатка (после налога). Та же формула в ЗП/аналитике.
        $dealMarginPct = \App\Services\PayrollService::marginPct($dealBudget, $dealRemainder, $dealTax);
        $dealBonusRate = \App\Services\PayrollService::bonusRateForMargin($dealMarginPct);
        $dealBonus = \App\Services\PayrollService::marginBonus($dealBudget, $dealRemainder, $dealTax);

        // Галочка-гейт текущего этапа (настраивается в Настройки → Этапы).
        $gateStage = self::gateStage($deal);
        $stageTask = null;
        if ($gateStage) {
            $openTask = $deal->tasks()->where('title', 'like', $gateStage->gate_task_title.'%')->where('status', '!=', 'done')->orderBy('due_date')->first();
            $gateRole = $gateStage->gate_task_role ?: 'financist';
            $stageTask = [
                'label' => $gateStage->gate_task_title.' — выполнено',
                'done' => $openTask === null,
                'due' => optional($openTask?->due_date)->toDateTimeString(),
                'role' => $gateRole,
                'roleLabel' => self::GATE_ROLE_LABELS[$gateRole] ?? $gateRole,
            ];
        }

        return Inertia::render('Deals/Show', [
            'deal' => $deal,
            'stageTask' => $stageTask,
            // Остатки касса/банк — бухгалтеру в форме расхода («доступно N»);
            // менеджеру деньги компании не показываем.
            'balances' => request()->user()->hasAnyRole(['admin', 'financist'])
                ? $finance->companyBalances($deal->company_id ? (int) $deal->company_id : null)
                : null,
            // Склад компании сделки — для расходов по материалам (показ остатка).
            'materials' => \App\Models\Material::query()
                ->when($deal->company_id, fn ($q, $c) => $q->where('company_id', $c))
                ->orderBy('name')->get(['id', 'name', 'unit', 'quantity', 'price']),
            'profit' => [
                'budget' => $dealBudget,
                'tax' => $dealTax, 'taxRate' => $taxRate * 100,
                'expense' => $confirmedExpense,
                'remainder' => $dealRemainder,
                'bonus' => $dealBonus, 'bonusRate' => round($dealBonusRate * 100, 1),
                'company' => round($dealRemainder - $dealBonus, 2),
            ],
            'chatId' => $dealChat->id,
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stages' => DealStage::with('translations')->where('is_active', true)
                ->when($deal->company_id, fn ($q, $c) => $q->where(fn ($w) => $w->where('company_id', $c)->orWhereNull('company_id')))
                ->orderBy('order')->get()
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
        $this->assertNotFrozen($request, $deal);
        $data = $request->validated();
        // Название сделки зеркалит название компании (поле убрано из UI).
        $data['name'] = $data['company_name'];
        $deal->update($data);

        return back()->with('success', 'Сделка обновлена.');
    }

    /**
     * Галочка-гейт текущего этапа: закрывает гейт-задачу («Выставить акт…»,
     * «Подтвердить дизайн…» и т.п.), после чего сделку можно двигать дальше.
     * Ставит её роль гейта этапа (дизайнер — «Дизайн и расчет», снабженец —
     * «Закуп ЛДСП,МДФ», бухгалтер — АКТ/ЭСФ/Оплата) или админ.
     */
    public function completeStageTask(Request $request, Deal $deal): RedirectResponse
    {
        // Не 'update': дизайнер/снабженец не редактируют сделку, но гейт ставят.
        $this->authorize('view', $deal);

        $gateStage = self::gateStage($deal);
        abort_unless($gateStage !== null, 404);

        $gateRole = $gateStage->gate_task_role ?: 'financist';
        abort_unless(
            $request->user()->hasRole('admin') || $request->user()->hasRole($gateRole),
            403,
            'Галочку ставит только '.(self::GATE_ROLE_LABELS[$gateRole] ?? $gateRole).' или админ.'
        );

        $deal->tasks()->where('title', 'like', $gateStage->gate_task_title.'%')->where('status', '!=', 'done')
            ->get()->each(fn ($t) => $t->update(['status' => 'done', 'completed_at' => now()]));

        return back()->with('success', 'Галочка поставлена — сделку можно переводить дальше.');
    }

    /**
     * После «Акт утверждение» сделку изменяет только бухгалтер/админ:
     * менеджеру (и директору) недоступны редактирование, смена ответственного
     * и удаление сделки на этапах АКТ / ЭСФ / Оплата успешно.
     */
    private function assertNotFrozen(Request $request, Deal $deal): void
    {
        if ($request->user()->hasAnyRole(['admin', 'financist'])) {
            return;
        }
        $companyId = $deal->company_id ? (int) $deal->company_id : null;
        $frozenIds = collect([
            DealStage::actStage($companyId)?->id,
            DealStage::esfStage($companyId)?->id,
            DealStage::wonStage($companyId)?->id,
        ])->filter();

        abort_if(
            $frozenIds->contains($deal->deal_stage_id),
            403,
            'После «Акт утверждение» сделку изменяет только бухгалтер или админ.'
        );
    }

    /** Текущий этап сделки, если на нём настроен гейт (или null). */
    /** Подписи ролей гейт-задач (для сообщений и карточки сделки). */
    private const GATE_ROLE_LABELS = ['financist' => 'бухгалтер', 'designer' => 'дизайнер', 'supplier' => 'снабженец', 'manager' => 'менеджер', 'director' => 'директор', 'admin' => 'админ'];

    private static function gateStage(Deal $deal): ?DealStage
    {
        $stage = $deal->stage ?? DealStage::find($deal->deal_stage_id);

        return $stage && $stage->hasGate() ? $stage : null;
    }

    public function updateStage(Request $request, Deal $deal, StageTransitionService $transitions): RedirectResponse
    {
        $this->authorize('update', $deal);

        $validated = $request->validate(['deal_stage_id' => ['required', 'exists:deal_stages,id']]);
        $target = DealStage::findOrFail($validated['deal_stage_id']);

        // Причину отказа (гейты этапов) показываем красным баннером, а не
        // тихой ошибкой валидации, которую на канбане не видно.
        try {
            $transitions->moveToStage($deal, $target);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', 'Этап сделки обновлён.');
    }

    public function destroy(Request $request, Deal $deal): RedirectResponse
    {
        $this->authorize('delete', $deal);
        $this->assertNotFrozen($request, $deal);
        $deal->delete();

        // Не back(): удаляют из карточки сделки, а «назад» — это страница
        // только что удалённой сделки → 404 (No query results for model Deal).
        return redirect()->route('deals.index')->with('success', 'Сделка удалена.');
    }

    /**
     * Массовое удаление из списка. Права те же, что у одиночного удаления:
     * DealPolicy::delete (только admin, в пределах своей компании) — проверяется
     * ПО КАЖДОЙ сделке до удаления, чтобы не удалить «частично».
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $deals = Deal::whereIn('id', $data['ids'])->get();
        abort_if($deals->isEmpty(), 404);
        foreach ($deals as $deal) {
            $this->authorize('delete', $deal);
        }

        // each->delete() (не массовый query) — сохраняет SoftDeletes и аудит-события.
        $deals->each->delete();

        return back()->with('success', 'Удалено сделок: '.$deals->count().'.');
    }

    public function advance(Deal $deal, StageTransitionService $transitions): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $deal);
        // Следующий этап — по ПОЗИЦИИ в воронке (не по order > current): при
        // задвоенном order переход не перескакивает соседний этап.
        $funnel = DealStage::funnel($deal->company_id ? (int) $deal->company_id : null)->values();
        $idx = $funnel->search(fn ($s) => $s->id === $deal->deal_stage_id);
        $next = $idx !== false ? $funnel->get($idx + 1) : $funnel->first();
        if ($next) {
            try {
                $transitions->moveToStage($deal, $next);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return back()->with('error', collect($e->errors())->flatten()->first());
            }
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
        $this->assertNotFrozen($request, $deal);
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
            ->when(\App\Support\CurrentCompany::id(), fn ($q, $c) => $q->where('company_id', $c))
            ->whereNotNull('deadline')
            ->whereDate('deadline', '<', $today)
            ->whereNotIn('status', ['closed', 'cancelled'])
            // ЭСФ и «Оплата успешно» — не просрочка; на «Акт утверждение»
            // просроченная сделка ПОКАЗЫВАЕТСЯ (по stage_type, имя ненадёжно).
            ->whereDoesntHave('stage', fn ($s) => $s->where('is_won', true)->orWhere('stage_type', 'esf'))
            ->when(! $request->user()->hasAnyRole(['admin', 'director', 'financist']), fn ($q) => $q->where('responsible_user_id', $request->user()->id))
            ->orderBy('deadline')
            ->get()
            ->map(function ($d) use ($today) {
                $d->overdue_days = (int) \Illuminate\Support\Carbon::parse($d->deadline)->startOfDay()->diffInDays($today);

                return $d;
            });

        // Просроченные заказы цеха: у заказа свой дедлайн (унаследован от
        // сделки) — горящий цех виден на той же странице.
        $projects = \App\Models\Project::query()
            ->with(['responsible:id,name,avatar', 'stage:id,name,color', 'deal:id,number,company_name,company_id'])
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('deadline')
            ->whereDate('deadline', '<', $today)
            ->when(\App\Support\CurrentCompany::id(), fn ($q, $c) => $q->whereHas('deal', fn ($d) => $d->where('company_id', $c)))
            ->when(! $request->user()->hasAnyRole(['admin', 'director', 'financist']), fn ($q) => $q->where('responsible_user_id', $request->user()->id))
            ->orderBy('deadline')
            ->get()
            ->map(function ($p) use ($today) {
                $p->overdue_days = (int) \Illuminate\Support\Carbon::parse($p->deadline)->startOfDay()->diffInDays($today);

                return $p;
            });

        return Inertia::render('Deals/Overdue', ['deals' => $deals, 'projects' => $projects]);
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

        // Изоляция фирм: подсказки по БИН — только по сделкам ТЕКУЩЕЙ компании,
        // иначе менеджер BAIA по БИН увидел бы бюджеты/сделки ASU.
        $client = Client::where('inn', $bin)->first();
        $deal = Deal::forCurrentCompany()->where('bin', $bin)->whereNotNull('company_name')->latest()->first();

        $match = null;
        if ($client) {
            $match = ['company_name' => $client->name, 'bin' => $client->inn, 'phone' => $client->phone, 'address' => $client->address];
        } elseif ($deal) {
            $match = ['company_name' => $deal->company_name, 'bin' => $deal->bin, 'phone' => null, 'address' => null];
        }

        // История по БИН — тоже только текущая компания.
        $history = Deal::forCurrentCompany()->where('bin', $bin)->with('stage:id,name,color')
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
