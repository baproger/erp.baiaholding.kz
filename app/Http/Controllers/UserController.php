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

        $users = User::query()
            ->with(['department:id,name', 'roles:id,name', 'companies:companies.id,name'])
            ->when($request->string('search')->toString(), fn ($q, $s) => $q
                ->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'avatar' => $u->avatar,
                'email' => $u->email,
                'phone' => $u->phone,
                'is_active' => $u->is_active,
                'department' => $u->department,
                'department_id' => $u->department_id,
                'role' => $u->roles->first()?->name,
                'company_ids' => $u->companies->pluck('id'),
                'company_names' => $u->companies->pluck('name')->join(', '),
                'salary' => (float) $u->salary,
                'has_contract' => (bool) $u->contract_path,
            ]);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => $request->only('search'),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'roles' => Role::orderBy('name')->pluck('name'),
            'companies' => \App\Models\Company::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
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
            'phone' => $data['phone'] ?? null,
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

        return back()->with('success', 'Сотрудник добавлен.');
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $data = $request->validated();
        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'department_id' => $data['department_id'] ?? null,
            'phone' => $data['phone'] ?? null,
            'salary' => $data['salary'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);
        if ($request->hasFile('contract')) {
            if ($user->contract_path) {
                \Illuminate\Support\Facades\Storage::delete($user->contract_path);
            }
            $user->update(['contract_path' => $request->file('contract')->store('contracts')]);
        }
        if (! empty($data['password'])) {
            $user->update(['password' => Hash::make($data['password'])]);
        }
        $this->guardRoleAssignment($request, $data['role'], $user);
        $user->syncRoles([$data['role']]);
        $user->companies()->sync($this->companyIds($request));

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
        $actorIsAdmin = $request->user()->hasRole('admin');
        $targetWasAdmin = $target?->hasRole('admin') ?? false;

        // Выдать или снять роль admin может только admin.
        if (($role === 'admin' || $targetWasAdmin) && ! $actorIsAdmin) {
            abort(403, 'Роль «Администратор» назначает и снимает только администратор.');
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

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);
        // Последнего активного админа нельзя деактивировать — иначе система
        // останется без владельца (Gate::before на admin).
        if ($user->hasRole('admin') && $this->activeAdminCount() <= 1) {
            abort(403, 'Нельзя деактивировать последнего администратора.');
        }

        // Soft-delete + deactivate rather than hard removal.
        $user->update(['is_active' => false]);
        $user->delete();

        return back()->with('success', 'Сотрудник деактивирован.');
    }
}
