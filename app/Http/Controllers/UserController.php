<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        // Изоляция фирм: работающий только в ASU не показывается в BAIA.
        // В режиме «Все компании» (админ/бухгалтер) видны обе.
        $companyId = \App\Support\CurrentCompany::id() ?: null;

        // Без пагинации: страница группирует сотрудников по отделам,
        // поиск и фильтры — мгновенные на клиенте.
        $users = User::query()->ofCompany($companyId)
            ->with(['department:id,name,code,company_id', 'roles:id,name', 'companies:companies.id,name'])
            // Открытые дела — для модалки «Уволить → передать дела» (одним запросом).
            ->withCount([
                'responsibleDeals as open_deals' => fn ($q) => $q->whereNotIn('status', ['closed', 'cancelled'])
                    ->whereNotIn('deal_stage_id', \App\Models\DealStage::where('is_won', true)->select('id')),
                'responsibleProjects as open_projects' => fn ($q) => $q->where('status', 'active'),
                'assignedTasks as open_tasks' => fn ($q) => $q->where('status', '!=', 'done'),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'avatar' => $u->avatar,
                'email' => $u->email,
                'phone' => $u->phone,
                'birth_date' => $u->birth_date?->toDateString(),
                'hired_at' => $u->hired_at?->toDateString(),
                'is_active' => $u->is_active,
                // Работает / Уволен (07.10.2026): уволенный остаётся в списке на своей вкладке.
                'status' => $u->status ?: User::STATUS_WORKING,
                'fired_at' => $u->fired_at?->toDateString(),
                'fired_note' => $u->fired_note,
                'open_deals' => (int) $u->open_deals,
                'open_projects' => (int) $u->open_projects,
                'open_tasks' => (int) $u->open_tasks,
                'department' => $u->department,
                'department_id' => $u->department_id,
                // Отделы свои у каждой фирмы; code — общий ключ одноимённых
                // отделов BAIA/ASU, по нему сотрудник обеих фирм попадает
                // в свой отдел в секции каждой фирмы.
                'department_code' => $u->department?->code,
                'workshops' => $u->workshops ?? [],
                'role' => $u->roles->first()?->name,
                'company_ids' => $u->companies->pluck('id'),
                'company_names' => $u->companies->pluck('name')->join(', '),
                'salary' => (float) $u->salary,
                'has_contract' => (bool) $u->contract_path,
                'has_login_code' => \App\Support\LoginSecurity::hasActiveCode($u),
            ])
            ->values();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'departments' => Department::where('is_active', true)->forCompany($companyId)
                ->orderBy('company_id')->orderBy('name')
                ->get(['id', 'company_id', 'name', 'code', 'head_user_id']),
            'roles' => Role::orderBy('name')->pluck('name'),
            // Секции строятся по этим фирмам: в режиме одной фирмы — только она.
            'companies' => \App\Models\Company::where('is_active', true)
                ->when($companyId, fn ($q, $c) => $q->whereKey($c))
                ->orderBy('id')->get(['id', 'name']),
            'can' => [
                'manage' => $request->user()->can('create', User::class),
                // Код входа / сброс устройств — только админ.
                'security' => $request->user()->hasRole('admin'),
                // Уволить — у кого есть право удаления сотрудника; вернуть — только админ.
                'fire' => $request->user()->can('delete', new User),
                'restore' => $request->user()->hasRole('admin'),
            ],
            // Цеха холдинга (у BAIA два) — чекбоксы доступа в форме сотрудника.
            'workshopOptions' => \App\Models\Company::where('is_active', true)->pluck('id')
                ->flatMap(fn ($id) => \App\Models\ProjectStage::workshopsFor((int) $id))->unique()->values(),
        ]);
    }

    /**
     * Профиль сотрудника: сделки, заказы цеха, задачи и ЗП в одном месте.
     * Видит руководство (user.view) или сам сотрудник; деньги (оклад/бонус) —
     * только admin/financist и сам сотрудник (директор — наблюдатель без ЗП-детали).
     */
    public function show(
        Request $request,
        User $user,
        \App\Services\PayrollService $payroll,
        \App\Services\EmployeeDebtService $debts
    ): Response {
        $viewer = $request->user();
        abort_unless($viewer->can('view', $user) || $viewer->id === $user->id, 403);

        $seesMoney = $viewer->hasAnyRole(['admin', 'financist', 'director']) || $viewer->id === $user->id;

        // Месяц денежных блоков (корректировки, долг) — как на стр. Зарплата,
        // чтобы цифры профиля и ведомости сходились.
        $month = preg_match('/^\d{4}-\d{2}$/', $request->string('month')->toString())
            ? $request->string('month')->toString() : now()->format('Y-m');

        $deals = \App\Models\Deal::forCurrentCompany()
            ->where('responsible_user_id', $user->id)
            ->with('stage:id,name,is_won')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'number', 'company_name', 'budget', 'deal_stage_id', 'status', 'deadline', 'created_at'])
            ->map(fn ($d) => [
                'id' => $d->id,
                'number' => $d->number,
                'company_name' => $d->company_name,
                'budget' => $seesMoney ? (float) $d->budget : null,
                'stage' => $d->stage?->name,
                'is_won' => (bool) $d->stage?->is_won,
                'status' => $d->status,
                'deadline' => $d->deadline?->toDateString(),
            ]);

        $projects = \App\Models\Project::query()
            ->where('responsible_user_id', $user->id)
            ->with('stage:id,name')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'number', 'name', 'workshop', 'project_stage_id', 'status', 'deadline'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'number' => $p->number,
                'name' => $p->name,
                'workshop' => $p->workshop,
                'stage' => $p->stage?->name,
                'status' => $p->status,
                'deadline' => $p->deadline?->toDateString(),
            ]);

        $tasks = \App\Models\Task::where('assignee_id', $user->id)
            ->orderByRaw("status = 'done'")->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'title', 'status', 'priority', 'due_date'])
            ->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'status' => $t->status,
                'priority' => $t->priority,
                'due_date' => $t->due_date?->toDateString(),
                'overdue' => $t->status !== 'done' && $t->due_date && $t->due_date->isPast(),
            ]);

        // ЗП-строка из единого источника правды (как на странице Зарплата).
        $payrollRow = $seesMoney
            ? $payroll->perUser(true)->firstWhere('uid', $user->id)
            : null;
        // Корректировки — за ВЫБРАННЫЙ месяц (раньше показывались последние 20
        // вперемешку, и профиль не сходился с ведомостью).
        $monthStart = $month.'-01';
        $monthEnd = \Illuminate\Support\Carbon::parse($monthStart)->endOfMonth()->toDateString();
        $adjustments = $seesMoney
            ? \App\Models\PayrollAdjustment::where('user_id', $user->id)
                ->whereDate('date', '>=', $monthStart)->whereDate('date', '<=', $monthEnd)
                ->orderByDesc('date')->get()
                ->map(fn ($a) => [
                    'id' => $a->id, 'type' => $a->type, 'amount' => (float) $a->amount,
                    'days' => $a->days !== null ? (float) $a->days : null,
                    'date' => $a->date?->toDateString(), 'note' => $a->note,
                ])
            : [];

        // Долг перед компанией — тот же расчёт, что в ведомости ЗП: гасится
        // фиксированной суммой в месяц и только из бонуса этого месяца.
        $ofMonth = $seesMoney ? $debts->forMonth($user->id, $month) : collect();
        $debtPlan = $ofMonth->isNotEmpty()
            ? $debts->planFrom($ofMonth, $month, (float) ($payroll->bonusByUserForMonth($month)[$user->id] ?? 0))
            : null;

        $headOf = Department::where('head_user_id', $user->id)->pluck('name');

        return Inertia::render('Users/Show', [
            'person' => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->avatar,
                'email' => $user->email,
                'phone' => $user->phone,
                'birth_date' => $user->birth_date?->toDateString(),
                'hired_at' => $user->hired_at?->toDateString(),
                'is_active' => $user->is_active,
                'status' => $user->status ?: User::STATUS_WORKING,
                'fired_at' => $user->fired_at?->toDateString(),
                'fired_note' => $user->fired_note,
                'department' => $user->department?->name,
                'head_of' => $headOf,
                'role' => $user->roles->first()?->name,
                'companies' => $user->companies->pluck('name'),
                'salary' => $seesMoney ? (float) $user->salary : null,
                'has_contract' => (bool) $user->contract_path,
            ],
            'deals' => $deals,
            'projects' => $projects,
            'tasks' => $tasks,
            'payrollRow' => $payrollRow,
            'adjustments' => $adjustments,
            'month' => $month,
            'debts' => $ofMonth->map(fn ($d) => [
                'id' => $d->id,
                'amount' => (float) $d->amount,
                'monthly_amount' => (float) $d->monthly_amount,
                'paid' => $d->paidSum(),
                'remaining' => $d->remaining(),
                'paid_this_month' => (float) ($d->payments->firstWhere('month', $month)?->amount ?? 0),
                'closed' => $d->closed_at !== null,
                'date' => optional($d->date)->toDateString(),
                'note' => $d->note,
            ])->values(),
            'debtPlan' => $debtPlan,
            'can' => ['manage' => $viewer->can('update', $user), 'restore' => $viewer->hasRole('admin')],
        ]);
    }

    /**
     * Экспорт списка сотрудников в CSV (открывается в Excel): имя, отдел, роль,
     * телефон, email, компании, даты. Только для тех, кто видит страницу.
     */
    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('viewAny', User::class);

        $roleLabels = [
            'admin' => 'СЕО (админ)', 'director' => 'Директор', 'financist' => 'Финансист-Бухгалтер',
            'manager' => 'Менеджер', 'employee' => 'Сотрудник (цех)', 'lawyer' => 'Юрист',
            'cook' => 'Повар', 'designer' => 'Дизайнер', 'supplier' => 'Снабженец',
        ];
        $users = User::with(['department:id,name', 'roles:id,name', 'companies:companies.id,name'])
            ->orderBy('name')->get();

        return response()->streamDownload(function () use ($users, $roleLabels) {
            $out = fopen('php://output', 'w');
            // BOM — иначе Excel открывает кириллицу кракозябрами.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Имя', 'Отдел', 'Роль', 'Телефон', 'Email', 'Компании', 'В компании с', 'День рождения', 'Статус'], ';');
            foreach ($users as $u) {
                fputcsv($out, [
                    $u->name,
                    $u->department?->name ?? '—',
                    $roleLabels[$u->roles->first()?->name] ?? ($u->roles->first()?->name ?? '—'),
                    $u->phone ?? '—',
                    $u->email,
                    $u->companies->pluck('name')->join(', '),
                    $u->hired_at?->format('d.m.Y') ?? '—',
                    $u->birth_date?->format('d.m.Y') ?? '—',
                    $u->is_active ? 'Активен' : 'Отключён',
                ], ';');
            }
            fclose($out);
        }, 'Сотрудники — '.now()->format('d.m.Y').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Держит отдел сотрудника в его же фирме. Отделы принадлежат фирме, а
     * список фирм можно поменять в любой момент — без этого сотрудник остаётся
     * в отделе фирмы, где он больше не работает (и, например, считается по
     * чужой норме часов). Переставляем на одноимённый отдел своей фирмы (общий
     * `code`); такого нет — отдел снимаем, чтобы не врал.
     */
    private function realignDepartment(User $user): void
    {
        $user->load(['department', 'companies']);
        $department = $user->department;
        $companyIds = $user->companies->pluck('id');

        if (! $department || $companyIds->isEmpty() || $companyIds->contains($department->company_id)) {
            return;
        }

        $twin = Department::where('code', $department->code)
            ->whereIn('company_id', $companyIds)
            ->where('is_active', true)->first();

        $user->update(['department_id' => $twin?->id]);

        if ($twin) {
            $user->departments()->syncWithoutDetaching([$twin->id]);
        }
        // Членство в отделе покинутой фирмы убираем — иначе он висел бы там.
        $user->departments()->detach($department->id);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validated();
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'department_id' => $data['department_id'] ?? null,
            'workshops' => array_values(array_filter($data['workshops'] ?? [])) ?: null,
            'phone' => $data['phone'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'hired_at' => $data['hired_at'] ?? null,
            'salary' => $data['salary'] ?? 0,
            'contract_path' => $request->hasFile('contract') ? $request->file('contract')->store('contracts') : null,
            'is_active' => $data['is_active'] ?? true,
            'language' => 'ru',
        ]);
        $this->guardRoleAssignment($request, $data['role']);
        $user->assignRole($data['role']);

        if ($user->department_id) {
            $user->departments()->syncWithoutDetaching([$user->department_id]);
        }
        // Компании сотрудника (BAIA / ASU, можно обе); без выбора — привязка к обеим.
        $user->companies()->sync($this->companyIds($request));
        \Illuminate\Support\Facades\Cache::forget('user_companies.'.$user->id);
        \Illuminate\Support\Facades\Cache::forget('user_company_ids.'.$user->id);
        $this->realignDepartment($user);

        return back()->with('success', 'Сотрудник добавлен.');
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $data = $request->validated();
        // ДО записи полей: не-админ не редактирует админа (иначе поля успели
        // бы обновиться до 403 на роли).
        $this->guardRoleAssignment($request, $data['role'], $user);
        $wasActive = (bool) $user->is_active;
        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'department_id' => $data['department_id'] ?? null,
            'workshops' => array_values(array_filter($data['workshops'] ?? [])) ?: null,
            'phone' => $data['phone'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'hired_at' => $data['hired_at'] ?? null,
            'salary' => $data['salary'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);
        if ($request->hasFile('contract')) {
            if ($user->contract_path) {
                \Illuminate\Support\Facades\Storage::delete($user->contract_path);
            }
            $user->update(['contract_path' => $request->file('contract')->store('contracts')]);
        }
        if ($wasActive && ! $user->is_active) {
            // Отключили: сессии, устройства и код — обнуляются сразу.
            \App\Support\LoginSecurity::revokeAccess($user, $request);
        }
        if (! empty($data['password'])) {
            $user->update(['password' => Hash::make($data['password'])]);
            // Новый пароль — выход на всех устройствах (свою сессию админ сохраняет).
            \App\Support\LoginSecurity::bumpStamp($user, keepCurrentSession: $user->id === $request->user()->id);
        }
        $user->syncRoles([$data['role']]);
        $user->companies()->sync($this->companyIds($request));
        \Illuminate\Support\Facades\Cache::forget('user_companies.'.$user->id);
        \Illuminate\Support\Facades\Cache::forget('user_company_ids.'.$user->id);
        $this->realignDepartment($user);

        return back()->with('success', 'Сотрудник обновлён.');
    }

    /**
     * Трудовой договор: скачать может руководство или сам сотрудник.
     */
    public function contract(Request $request, User $user): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['admin', 'director', 'financist']) || $request->user()->id === $user->id,
            403
        );
        abort_unless($user->contract_path && \Illuminate\Support\Facades\Storage::exists($user->contract_path), 404);

        return \Illuminate\Support\Facades\Storage::download(
            $user->contract_path,
            'Договор — '.$user->name.'.'.pathinfo($user->contract_path, PATHINFO_EXTENSION)
        );
    }

    /**
     * Validated company ids from the form; empty selection = both firms
     * (safe default so the employee is never locked out).
     */
    private function companyIds(Request $request): array
    {
        $ids = collect($request->input('company_ids', []))->map(fn ($v) => (int) $v)->filter();
        $valid = \App\Models\Company::where('is_active', true)->pluck('id');

        $picked = $ids->intersect($valid);

        return ($picked->isEmpty() ? $valid : $picked)->values()->all();
    }

    /**
     * Роль admin назначает/снимает ТОЛЬКО действующий admin (директор — нет,
     * иначе наблюдатель выдал бы себе полный доступ). Плюс защита последнего
     * активного администратора от разжалования при обновлении.
     */
    private function guardRoleAssignment(Request $request, string $role, ?User $target = null): void
    {
        // Именно СУПЕР-админ: CEO проходит hasRole('admin'), но сюда — нет.
        $actorIsAdmin = $request->user()->isSuperAdmin();
        $targetWasAdmin = $target?->isSuperAdmin() ?? false;

        // Выдать/снять роль admin и вообще править супер-админа может только супер-админ.
        if (($role === 'admin' || $targetWasAdmin) && ! $actorIsAdmin) {
            abort(403, 'Супер-администратора и роль «Администратор» меняет только супер-администратор.');
        }
        // Нельзя разжаловать последнего активного админа.
        if ($targetWasAdmin && $role !== 'admin' && $this->activeAdminCount() <= 1) {
            abort(403, 'Нельзя снять роль с последнего администратора.');
        }
    }

    private function activeAdminCount(): int
    {
        return User::where('is_active', true)->role('admin')->count();
    }

    /**
     * «Уволить» (правило владельца от 07.10.2026). Сотрудник НЕ удаляется:
     * статус fired + дата; вход закрыт, сессии/устройства сброшены, вся
     * история остаётся. Передача открытых дел преемнику — по желанию.
     */
    public function destroy(Request $request, User $user, \App\Services\EmployeeStatusService $staff): RedirectResponse
    {
        $this->authorize('delete', $user);
        // Аккаунт администратора не увольняется через систему НИКЕМ — даже
        // другим админом (24.08.2026 на проде удалили админа). Чтобы убрать
        // админа, сначала смените ему роль — смена роли пишется в аудит.
        if ($user->hasRole('admin')) {
            abort(403, 'Аккаунт администратора уволить нельзя. Сначала смените ему роль.');
        }

        $data = $request->validate([
            'fired_at' => ['nullable', 'date'],
            'fired_note' => ['nullable', 'string', 'max:255'],
            'successor_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $successor = null;
        if (! empty($data['successor_user_id'])) {
            $successor = User::working()->whereKey($data['successor_user_id'])->first();
            if (! $successor || $successor->id === $user->id) {
                return back()->withErrors(['successor_user_id' => 'Передать дела можно только работающему сотруднику (не самому увольняемому).']);
            }
        }
        $firedAt = ! empty($data['fired_at']) ? \Illuminate\Support\Carbon::parse($data['fired_at']) : now();

        $moved = $staff->fire($user, $firedAt, $data['fired_note'] ?? null, $successor, $request);

        $message = "Сотрудник «{$user->name}» уволен с ".$firedAt->format('d.m.Y').'.';
        if ($successor) {
            $message .= " Передано: {$moved['deals']} сделок, {$moved['projects']} заказов, {$moved['tasks']} задач → {$successor->name}.";
        }

        return back()->with('success', $message);
    }

    /** «Восстановить» уволенного (вернулся на работу) — только админ. */
    public function restore(Request $request, User $user, \App\Services\EmployeeStatusService $staff): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403, 'Восстанавливает только администратор.');
        $staff->restore($user);

        return back()->with('success', "Сотрудник «{$user->name}» снова работает.");
    }

    /**
     * Код входа (второй фактор): админ выпускает 6-значный код и сам передаёт
     * его сотруднику. Показывается один раз, в БД — только хеш, живёт 24 часа.
     */
    public function issueLoginCode(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403);
        if (! $user->is_active) {
            return back()->with('error', 'Сотрудник отключён — код входа не выпускается.');
        }
        $code = \App\Support\LoginSecurity::issueCode($user, $request->user(), $request);

        return back()->with('login_code', [
            'user' => $user->name,
            'code' => $code,
            'expires_at' => now()->addHours(\App\Support\LoginSecurity::CODE_TTL_HOURS)->toIso8601String(),
        ]);
    }

    /** Сбросить доверенные устройства и сессии: при следующем входе снова нужен код. */
    public function revokeDevices(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403);
        \App\Support\LoginSecurity::revokeDevices($user, $request);
        if ($user->id === $request->user()->id) {
            \App\Support\LoginSecurity::stampSession($user, $request);
        }

        return back()->with('success', "Устройства «{$user->name}» сброшены — при следующем входе потребуется код.");
    }
}
