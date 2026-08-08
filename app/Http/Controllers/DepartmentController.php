<?php

namespace App\Http\Controllers;

use App\Http\Requests\DepartmentRequest;
use App\Models\Company;
use App\Models\Department;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Department::class);

        // Отделы свои у каждой фирмы. В режиме «Все» (admin/financist) видны
        // отделы обеих — с колонкой «Компания», чтобы одноимённые не путались.
        $companyId = CurrentCompany::id() ?: null;

        $departments = Department::query()
            ->forCompany($companyId)
            ->withCount('members')
            ->with(['head:id,name', 'company:id,name'])
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('company_id')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Departments/Index', [
            'departments' => $departments,
            'filters' => $request->only('search'),
            // Селект «Руководитель» — только сотрудники этой фирмы.
            'users' => \App\Models\User::where('is_active', true)
                ->when($companyId, fn ($q) => $q->whereHas('companies', fn ($c) => $c->where('companies.id', $companyId)))
                ->orderBy('name')->get(['id', 'name', 'department_id']),
            'companies' => Company::where('is_active', true)->orderBy('id')->get(['id', 'name']),
            'currentCompanyId' => $companyId,
            'can' => [
                'create' => $request->user()->can('create', Department::class),
                'update' => $request->user()->can('update', Department::class),
                'delete' => $request->user()->can('delete', Department::class),
            ],
        ]);
    }

    public function store(DepartmentRequest $request): RedirectResponse
    {
        $this->authorize('create', Department::class);

        $data = Arr::except($request->validated(), 'company_ids');
        $companyIds = $this->targetCompanyIds($request);
        // Общий code у копий одного отдела в разных фирмах — по нему сотрудник,
        // работающий в обеих, попадает в одноимённый отдел в каждой секции.
        $code = $this->makeCode($data['name']);

        foreach ($companyIds as $companyId) {
            Department::create($data + ['company_id' => $companyId, 'code' => $code]);
        }

        return back()->with('success', count($companyIds) > 1
            ? 'Отдел создан в обеих фирмах.'
            : 'Отдел создан.');
    }

    public function update(DepartmentRequest $request, Department $department): RedirectResponse
    {
        $this->authorize('update', $department);
        // Фирму отдела не меняем: перенос отдела между фирмами утащил бы за собой
        // сотрудников и историю — для этого создаётся отдел в нужной фирме.
        $department->update(Arr::except($request->validated(), 'company_ids'));

        return back()->with('success', 'Отдел обновлён.');
    }

    public function destroy(Department $department): RedirectResponse
    {
        $this->authorize('delete', $department);
        $department->delete();

        return back()->with('success', 'Отдел удалён.');
    }

    /**
     * Фирмы, в которых создаётся отдел: выбранная в форме, «обе», либо — если
     * выбора нет — текущая фирма сессии.
     *
     * @return list<int>
     */
    private function targetCompanyIds(Request $request): array
    {
        $active = Company::where('is_active', true)->orderBy('id')->pluck('id');
        $requested = collect($request->input('company_ids', []))->map(fn ($v) => (int) $v)
            ->intersect($active)->values();

        if ($requested->isNotEmpty()) {
            return $requested->all();
        }

        $current = CurrentCompany::id();

        return $current && $active->contains($current) ? [$current] : $active->all();
    }

    /** Читаемый общий ключ отдела, уникальный среди уже существующих. */
    private function makeCode(string $name): string
    {
        $base = Str::slug($name) ?: 'dept';

        return $base.'-'.Str::lower(Str::random(6));
    }
}
