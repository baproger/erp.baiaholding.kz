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

        if ($screen->kind === 'office') {
            return $this->office($screen);
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
            ->addSelect(['stage_entered_at' => \App\Models\ProjectStageLog::select('entered_at')
                ->whereColumn('project_id', 'projects.id')->whereNull('left_at')
                ->latest('entered_at')->limit(1)])
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
                'stage_entered_at' => $p->stage_entered_at,
            ]);

        return Inertia::render('Screen/Workshop', [
            'screen' => ['workshop' => $screen->workshop, 'company' => $screen->company?->name],
            'stages' => $stages,
            'projects' => $projects,
        ]);
    }

    /** Экран «Офис»: сделки по этапам + лидеры менеджеров (без сумм). */
    private function office(WorkshopScreen $screen): Response
    {
        $companyId = $screen->company_id ? (int) $screen->company_id : null;

        $stages = \App\Models\DealStage::funnel($companyId)
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName(), 'color' => $s->color]);

        $deals = \App\Models\Deal::query()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))
            ->where('status', 'active')
            ->with('responsible:id,name')
            ->latest()->get()
            ->map(fn ($d) => [
                'number' => $d->number,
                'name' => $d->company_name ?: $d->name,
                'stage_id' => $d->deal_stage_id,
                'manager' => $d->responsible?->name,
                'deadline' => optional($d->deadline)->toDateString(),
                'overdue' => $d->deadline?->isPast() ?? false,
            ]);

        // Лидеры: сколько сделок у каждого менеджера + сколько успешных.
        $base = \App\Models\Deal::query()->when($companyId, fn ($q, $c) => $q->where('company_id', $c))->whereNotNull('responsible_user_id');
        $totals = (clone $base)->where('status', '!=', 'cancelled')->groupBy('responsible_user_id')->selectRaw('responsible_user_id uid, count(*) c')->pluck('c', 'uid');
        $wonCounts = \App\Models\Deal::won()->when($companyId, fn ($q, $c) => $q->where('company_id', $c))->whereNotNull('responsible_user_id')->groupBy('responsible_user_id')->selectRaw('responsible_user_id uid, count(*) c')->pluck('c', 'uid');
        $leaders = \App\Models\User::whereIn('id', $totals->keys())->where('is_active', true)->get(['id', 'name', 'avatar'])
            ->map(fn ($u) => ['name' => $u->name, 'avatar' => $u->avatar, 'total' => (int) ($totals[$u->id] ?? 0), 'won' => (int) ($wonCounts[$u->id] ?? 0)])
            ->sortByDesc('total')->values();

        return Inertia::render('Screen/Office', [
            'screen' => ['company' => $screen->company?->name],
            'stages' => $stages,
            'deals' => $deals,
            'leaders' => $leaders,
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

    /** Настройки → Экраны: все цеха всех компаний, коды и статусы. */
    public function admin(Request $request): Response
    {
        $this->guardAdmin($request);

        $screens = WorkshopScreen::get()->keyBy(fn ($s) => ($s->company_id ?? 0).'|'.($s->workshop ?? '').'|'.$s->kind);
        $companies = \App\Models\Company::orderBy('id')->get(['id', 'name'])->map(function ($c) use ($screens) {
            $stages = ProjectStage::where('company_id', $c->id)->where('is_active', true)->get(['workshop']);
            $rows = $stages->pluck('workshop')->filter()->unique()->values()
                ->map(fn ($w) => ['workshop' => $w, 'label' => $w]);
            if ($rows->isEmpty() || $stages->contains(fn ($s) => $s->workshop === null)) {
                $rows->push(['workshop' => null, 'label' => 'Единый цех']);
            }

            return [
                'id' => $c->id, 'name' => $c->name,
                'rows' => $rows->map(fn ($r) => $r + [
                    'screen' => ($sc = $screens->get($c->id.'|'.($r['workshop'] ?? '').'|workshop'))
                        ? ['id' => $sc->id, 'code' => $sc->code, 'is_active' => $sc->is_active] : null,
                ])->values(),
                'office' => ($sc = $screens->get($c->id.'||office'))
                    ? ['id' => $sc->id, 'code' => $sc->code, 'is_active' => $sc->is_active] : null,
            ];
        });

        return Inertia::render('Settings/Screens', ['companies' => $companies]);
    }

    /** Включить/выключить экран (код перестаёт работать сразу). */
    public function toggle(Request $request, WorkshopScreen $screen): RedirectResponse
    {
        $this->guardAdmin($request);
        $screen->update(['is_active' => ! $screen->is_active]);

        return back()->with('success', $screen->is_active ? 'Экран включён.' : 'Экран отключён.');
    }

    private function guardAdmin(Request $request): void
    {
        abort_unless($request->user()->hasRole('admin') || $request->user()->can('setting.update'), 403);
    }

    /** Админка: выдать/перегенерировать код экрана цеха. */
    public function upsert(Request $request): RedirectResponse
    {
        $this->guardAdmin($request);
        $data = $request->validate([
            'company_id' => ['nullable', 'exists:companies,id'],
            'workshop' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', \Illuminate\Validation\Rule::in(['workshop', 'office'])],
        ]);

        WorkshopScreen::updateOrCreate(
            ['company_id' => $data['company_id'] ?? null, 'workshop' => $data['workshop'] ?? null, 'kind' => $data['kind'] ?? 'workshop'],
            ['code' => WorkshopScreen::freshCode(), 'is_active' => true]
        );

        return back()->with('success', 'Код экрана обновлён.');
    }
}
