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

        return Inertia::render('Settings/Stages', [
            'dealStages' => DealStage::orderBy('order')->get(),
            'projectStages' => ProjectStage::orderBy('order')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guard($request);
        $data = $this->validated($request);
        $model = $this->model($data['kind']);

        $max = $model::max('order') ?? 0;
        $stage = $model::create([
            'name' => $data['name'],
            'color' => $data['color'] ?? '#6B7280',
            'order' => $max + 1,
            'is_active' => true,
            'checklist' => [],
            'type' => $data['kind'] === 'project' ? 'project' : 'sale',
        ]);
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

    public function destroy(Request $request, string $kind, int $id): RedirectResponse
    {
        $this->guard($request);
        $model = $this->model($kind);
        $model::findOrFail($id)->delete();

        // Re-index remaining stages to keep order sequential (1..N, no gaps/zeros).
        $model::orderBy('order')->orderBy('id')->get()->each(fn ($s, $i) => $s->update(['order' => $i + 1]));

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
