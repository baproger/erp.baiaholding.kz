<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\WorkshopScreen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ТВ-экран цеха (без логина): вход по коду из админки. Один код = один цех —
 * чужой цех с этого экрана не открыть. Денег на экране нет.
 */
class WorkshopScreenController extends Controller
{
    public function show(Request $request): Response
    {
        $screen = WorkshopScreen::with('company:id,name,code')
            ->where('is_active', true)->find($request->session()->get('workshop_screen_id'));
        // Код сверяем при каждом показе: «Новый код» в админке отключает
        // все экраны, вошедшие по старому коду.
        if ($screen && $screen->code !== $request->session()->get('workshop_screen_code')) {
            $screen = null;
        }
        if (! $screen) {
            $request->session()->forget('workshop_screen_id');

            return Inertia::render('Screen/Enter');
        }

        $companyId = $screen->company_id ? (int) $screen->company_id : null;
        $stages = ProjectStage::companyQuery($companyId, $screen->workshop)
            ->with('translations')->get()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName(), 'color' => $s->color, 'is_completed' => $s->is_completed]);

        $projects = Project::query()
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->when($companyId, fn ($q, $c) => $q->whereHas('deal', fn ($d) => $d->where('company_id', $c)))
            ->when($screen->workshop, fn ($q, $w) => $q->where('workshop', $w))
            ->with(['stage:id,name', 'deal:id,number,company_name,address,deadline,description,note'])
            ->latest()->get()
            ->map(fn ($p) => [
                'id' => $p->id, 'number' => $p->number,
                'name' => $p->deal?->company_name ?: $p->name,
                'stage_id' => $p->project_stage_id,
                'address' => $p->deal?->address,
                'deadline' => optional($p->deal?->deadline ?? $p->deadline)->toDateString(),
                'overdue' => ($p->deal?->deadline ?? $p->deadline)?->isPast() ?? false,
                'description' => $p->deal?->description,
                'note' => $p->deal?->note,
            ]);

        return Inertia::render('Screen/Workshop', [
            'screen' => ['workshop' => $screen->workshop, 'company' => $screen->company?->name],
            'stages' => $stages,
            'projects' => $projects,
        ]);
    }

    public function enter(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $screen = WorkshopScreen::where('code', trim($data['code']))->where('is_active', true)->first();
        if (! $screen) {
            return back()->withErrors(['code' => 'Неверный код. Проверьте код экрана у администратора.']);
        }
        $request->session()->put('workshop_screen_id', $screen->id);
        $request->session()->put('workshop_screen_code', $screen->code);

        return redirect()->route('screen.show');
    }

    public function leave(Request $request): RedirectResponse
    {
        $request->session()->forget('workshop_screen_id');

        return redirect()->route('screen.show');
    }

    /** Админка: выдать/перегенерировать код экрана цеха (Настройки → Этапы). */
    public function upsert(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin') || $request->user()->can('setting.update'), 403);
        $data = $request->validate([
            'company_id' => ['nullable', 'exists:companies,id'],
            'workshop' => ['nullable', 'string', 'max:100'],
        ]);

        WorkshopScreen::updateOrCreate(
            ['company_id' => $data['company_id'] ?? null, 'workshop' => $data['workshop'] ?? null],
            ['code' => WorkshopScreen::freshCode(), 'is_active' => true]
        );

        return back()->with('success', 'Код экрана обновлён.');
    }
}
