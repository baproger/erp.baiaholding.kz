<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\PreDeal;
use App\Models\PreDealChecklistItem;
use App\Models\User;
use App\Services\DealNumberService;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Предварительные сделки (лоты): менеджер вносит цифры как в Excel, система
 * считает маржу. Маржа ≥ порога (15%) — «участвую», можно «Подтвердить» →
 * создаётся настоящая сделка на первом этапе воронки. Каждый менеджер видит
 * только свои лоты; руководство — все + рейтинг менеджеров.
 */
class PreDealController extends Controller
{
    private function leadership(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'director', 'financist']);
    }

    private function guardAccess(Request $request): void
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'director', 'financist', 'manager']), 403);
    }

    private function guardOwner(Request $request, PreDeal $preDeal): void
    {
        abort_unless($this->leadership($request) || $preDeal->user_id === $request->user()->id, 403);
    }

    public function index(Request $request): Response
    {
        $this->guardAccess($request);
        $lead = $this->leadership($request);
        $companyId = CurrentCompany::id() ?: null;

        $q = PreDeal::query()
            ->when($companyId, fn ($qq, $c) => $qq->where('company_id', $c))
            ->with(['user:id,name,avatar', 'deal:id,number'])
            ->latest();
        // Персонализация: менеджер видит ТОЛЬКО свои лоты.
        if (! $lead) {
            $q->where('user_id', $request->user()->id);
        } elseif ($mid = (int) $request->query('manager', 0)) {
            $q->where('user_id', $mid);
        }
        if ($st = $request->string('status')->toString()) {
            $q->where('status', $st);
        }

        // Рейтинг менеджеров (руководству): подтверждено лотов / на какую сумму.
        $stats = null;
        if ($lead) {
            $rows = PreDeal::query()
                ->when($companyId, fn ($qq, $c) => $qq->where('company_id', $c))
                ->selectRaw("user_id, count(*) total, sum(case when status = 'confirmed' then 1 else 0 end) confirmed, sum(case when status = 'confirmed' then contract_sum else 0 end) confirmed_sum")
                ->groupBy('user_id')->get();
            $names = User::whereIn('id', $rows->pluck('user_id'))->get(['id', 'name', 'avatar'])->keyBy('id');
            $stats = $rows->filter(fn ($r) => $names->has($r->user_id))
                ->map(fn ($r) => [
                    'name' => $names[$r->user_id]->name,
                    'avatar' => $names[$r->user_id]->avatar,
                    'total' => (int) $r->total,
                    'confirmed' => (int) $r->confirmed,
                    'sum' => (float) $r->confirmed_sum,
                ])->sortByDesc('confirmed')->values();
        }

        return Inertia::render('PreDeals/Index', [
            'preDeals' => $q->limit(300)->get(),
            'items' => PreDealChecklistItem::where('is_active', true)->orderBy('order')->get(['id', 'label']),
            'minMargin' => PreDeal::minMargin(),
            'taxPercent' => (float) \App\Models\Setting::get('tax_percent', 3),
            'leadership' => $lead,
            'stats' => $stats,
            'managers' => $lead ? User::role('manager')->where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
            'filters' => $request->only('manager', 'status'),
            'canManageChecklist' => $request->user()->hasAnyRole(['admin', 'financist']),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'lot_number' => ['nullable', 'string', 'max:100'],
            'bin' => ['nullable', 'string', 'max:40'],
            'customer' => ['nullable', 'string', 'max:255'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'client_phone' => ['nullable', 'string', 'max:40'],
            'product' => ['required', 'string', 'max:255'],
            'contract_sum' => ['required', 'numeric', 'min:1'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'partner_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'delivery' => ['nullable', 'numeric', 'min:0'],
            'commission' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guardAccess($request);
        $data = PreDeal::calculate($this->validated($request));
        $data['user_id'] = $request->user()->id;
        $data['company_id'] = CurrentCompany::id() ?: null;
        PreDeal::create($data);

        return back()->with('success', 'Предварительная сделка добавлена — маржа рассчитана.');
    }

    public function update(Request $request, PreDeal $preDeal): RedirectResponse
    {
        $this->guardOwner($request, $preDeal);
        if ($preDeal->status === 'confirmed') {
            return back()->with('error', 'Лот уже подтверждён в сделку — правки только в самой сделке.');
        }
        $preDeal->update(PreDeal::calculate($this->validated($request)));

        return back()->with('success', 'Пересчитано.');
    }

    public function destroy(Request $request, PreDeal $preDeal): RedirectResponse
    {
        $this->guardOwner($request, $preDeal);
        $preDeal->delete();

        return back()->with('success', 'Предварительная сделка удалена.');
    }

    /** Галочка чек-листа («КП в WhatsApp», «Позвонил клиенту»…). */
    public function check(Request $request, PreDeal $preDeal, PreDealChecklistItem $item): RedirectResponse
    {
        $this->guardOwner($request, $preDeal);
        $checks = $preDeal->checks ?? [];
        $checks[(string) $item->id] = ! ($checks[(string) $item->id] ?? false);
        $preDeal->update(['checks' => $checks]);

        return back();
    }

    /** «Подтвердить»: маржа ≥ порога → создаётся настоящая сделка. */
    public function confirm(Request $request, PreDeal $preDeal, DealNumberService $numbers): RedirectResponse
    {
        $this->guardOwner($request, $preDeal);
        if ($preDeal->status === 'confirmed') {
            return back()->with('error', 'Лот уже подтверждён.');
        }
        if ((float) $preDeal->margin < PreDeal::minMargin()) {
            return back()->with('error', 'Маржа '.$preDeal->margin.'% ниже порога '.rtrim(rtrim(number_format(PreDeal::minMargin(), 2, '.', ''), '0'), '.').'% — сделка отклонена.');
        }

        $companyId = $preDeal->company_id ? (int) $preDeal->company_id : null;
        $company = $companyId ? \App\Models\Company::find($companyId) : null;
        $customer = $preDeal->customer ?: $preDeal->product;

        $deal = Deal::create([
            'number' => $numbers->generate($company),
            'name' => $customer,
            'company_name' => $customer,
            'client_name' => $preDeal->client_name ?: ($preDeal->customer ?: '—'),
            'bin' => $preDeal->bin,
            'budget' => $preDeal->contract_sum,
            'status' => 'active',
            'company_id' => $companyId,
            'deal_stage_id' => DealStage::funnel($companyId)->first()?->id,
            'responsible_user_id' => $preDeal->user_id,
            'description' => 'Из предварительной сделки: товар — '.$preDeal->product
                .($preDeal->lot_number ? '; лот №'.$preDeal->lot_number : '')
                .($preDeal->client_phone ? '; контакт '.($preDeal->client_name ?: '').' '.$preDeal->client_phone : '')
                .'; закуп '.number_format((float) $preDeal->purchase_price, 0, '.', ' ')
                .'; расчётная маржа '.$preDeal->margin.'%',
        ]);
        $preDeal->update(['status' => 'confirmed', 'deal_id' => $deal->id]);

        return back()->with('success', 'Сделка '.$deal->number.' создана — смотрите на странице «Сделки».');
    }

    // ---- Чек-лист: пункты настраивают админ и финансист ----

    private function guardChecklist(Request $request): void
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'financist']), 403, 'Чек-лист настраивает админ или финансист.');
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $this->guardChecklist($request);
        $data = $request->validate(['label' => ['required', 'string', 'max:255']]);
        PreDealChecklistItem::create(['label' => $data['label'], 'order' => (int) PreDealChecklistItem::max('order') + 1]);

        return back()->with('success', 'Пункт чек-листа добавлен.');
    }

    public function updateItem(Request $request, PreDealChecklistItem $item): RedirectResponse
    {
        $this->guardChecklist($request);
        $data = $request->validate(['label' => ['required', 'string', 'max:255']]);
        $item->update(['label' => $data['label']]);

        return back()->with('success', 'Пункт обновлён.');
    }

    public function destroyItem(Request $request, PreDealChecklistItem $item): RedirectResponse
    {
        $this->guardChecklist($request);
        $item->delete();

        return back()->with('success', 'Пункт удалён.');
    }
}
