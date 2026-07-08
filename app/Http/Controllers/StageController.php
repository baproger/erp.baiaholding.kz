<?php

namespace App\Http\Controllers;

use App\Models\DealStage;
use App\Models\ProjectStage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StageController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless($request->user()->hasRole('admin') || $request->user()->can('setting.update'), 403);
    }

    private function model(string $kind): string
    {
        return $kind === 'project' ? ProjectStage::class : DealStage::class;
    }

    public function index(Request $request): Response
    {
        $this->guard($request);

        // Воронка сделок редактируется ДЛЯ ТЕКУЩЕЙ КОМПАНИИ (переключатель в
        // шапке); этапы цеха общие. Общие (без company_id) этапы тоже показываем.
        $companyId = \App\Support\CurrentCompany::id() ?: null;

        return Inertia::render('Settings/Stages', [
            'dealStages' => DealStage::query()
                ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w->where('company_id', $c)->orWhereNull('company_id')))
                ->orderBy('order')->get(),
            'projectStages' => ProjectStage::orderBy('order')->get(),
            'currentCompanyName' => $companyId ? \App\Models\Company::find($companyId)?->name : 'Все компании',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guard($request);
        $data = $this->validated($request);
        $model = $this->model($data['kind']);

        // Новый этап сделок попадает в воронку текущей компании.
        $companyId = $data['kind'] === 'deal' ? (\App\Support\CurrentCompany::id() ?: null) : null;

        $max = $model::query()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))
            ->max('order') ?? 0;
        $stage = $model::create([
            'name' => $data['name'],
            'color' => $data['color'] ?? '#6B7280',
            'order' => $max + 1,
            'is_active' => true,
            'checklist' => [],
            'type' => $data['kind'] === 'project' ? 'project' : 'sale',
        ] + ($data['kind'] === 'deal' ? ['company_id' => $companyId] : []));
        $stage->translations()->updateOrCreate(['locale' => app()->getLocale()], ['name' => $data['name']]);

        return back()->with('success', 'Этап добавлен.');
    }

    public function update(Request $request, string $kind, int $id): RedirectResponse
    {
        $this->guard($request);
        $data = $this->validated($request, false);
        $stage = $this->model($kind)::findOrFail($id);
        $stage->update(array_filter([
            'name' => $data['name'] ?? null,
            'color' => $data['color'] ?? null,
            'order' => $data['order'] ?? null,
        ], fn ($v) => $v !== null));

        if (! empty($data['name'])) {
            // Keep the current-locale translation in sync so the rename shows on cards.
            $stage->translations()->updateOrCreate(['locale' => app()->getLocale()], ['name' => $data['name']]);
        }

        return back()->with('success', 'Этап обновлён.');
    }

    /**
     * Переместить этап вверх/вниз — обмен order с соседним этапом.
     */
    public function move(Request $request, string $kind, int $id): RedirectResponse
    {
        $this->guard($request);
        $dir = $request->validate(['direction' => ['required', 'in:up,down']])['direction'];

        $model = $this->model($kind);
        $stage = $model::findOrFail($id);
        $neighbor = $model::query()
            // Обмен местами только внутри воронки своей компании.
            ->when($kind === 'deal', fn ($q) => $q->where('company_id', $stage->company_id))
            ->when($dir === 'up', fn ($q) => $q->where('order', '<', $stage->order)->orderByDesc('order'))
            ->when($dir === 'down', fn ($q) => $q->where('order', '>', $stage->order)->orderBy('order'))
            ->first();

        if (! $neighbor) {
            return back()->with('error', 'Этап уже '.($dir === 'up' ? 'первый' : 'последний').'.');
        }

        [$stageOrder, $neighborOrder] = [$stage->order, $neighbor->order];
        $stage->update(['order' => $neighborOrder]);
        $neighbor->update(['order' => $stageOrder]);

        return back()->with('success', 'Этап перемещён.');
    }

    public function destroy(Request $request, string $kind, int $id): RedirectResponse
    {
        $this->guard($request);
        $model = $this->model($kind);
        $stage = $model::findOrFail($id);
        $stage->delete();

        // Re-index remaining stages (внутри воронки своей компании) — 1..N без пробелов.
        $model::query()
            ->when($kind === 'deal', fn ($q) => $q->where('company_id', $stage->company_id))
            ->orderBy('order')->orderBy('id')->get()->each(fn ($s, $i) => $s->update(['order' => $i + 1]));

        return back()->with('success', 'Этап удалён.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $requireKind = true): array
    {
        return $request->validate([
            'kind' => [$requireKind ? 'required' : 'nullable', Rule::in(['deal', 'project'])],
            'name' => [$requireKind ? 'required' : 'nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
            'order' => ['nullable', 'integer'],
        ]);
    }
}
