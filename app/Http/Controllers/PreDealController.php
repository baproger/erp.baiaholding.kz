<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\PreDeal;
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
        // Фильтр по действию (участие / звонок / КП): что именно делали по лоту.
        if (in_array($act = $request->string('action')->toString(), PreDeal::ACTIONS, true)) {
            $q->where('action', $act);
        }
        // Фильтр по месяцу ВНЕСЕНИЯ лота (YYYY-MM): какие лоты в какой день вводили.
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m = $request->string('month')->toString())) {
            $start = $m.'-01';
            $q->whereDate('created_at', '>=', $start)
                ->whereDate('created_at', '<=', \Illuminate\Support\Carbon::parse($start)->endOfMonth()->toDateString());
        }

        // Рейтинг менеджеров (руководству): подтверждено лотов / на какую сумму
        // + разбивка по действиям (Участие/Звонок/КП → из них выиграно) —
        // соревнование видно по цифрам. Уважает фильтр месяца.
        $stats = null;
        if ($lead) {
            $rows = PreDeal::query()
                ->when($companyId, fn ($qq, $c) => $qq->where('company_id', $c))
                ->when(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ms = $request->string('month')->toString()), function ($qq) use ($ms) {
                    $start = $ms.'-01';
                    $qq->whereDate('created_at', '>=', $start)
                        ->whereDate('created_at', '<=', \Illuminate\Support\Carbon::parse($start)->endOfMonth()->toDateString());
                })
                ->selectRaw("user_id, count(*) total,
                    sum(case when status = 'confirmed' then 1 else 0 end) confirmed,
                    sum(case when status = 'confirmed' then contract_sum else 0 end) confirmed_sum,
                    sum(case when coalesce(action, 'participation') = 'participation' then 1 else 0 end) p_total,
                    sum(case when coalesce(action, 'participation') = 'participation' and status = 'confirmed' then 1 else 0 end) p_won,
                    sum(case when action = 'call' then 1 else 0 end) c_total,
                    sum(case when action = 'call' and status = 'confirmed' then 1 else 0 end) c_won,
                    sum(case when action = 'offer' then 1 else 0 end) o_total,
                    sum(case when action = 'offer' and status = 'confirmed' then 1 else 0 end) o_won")
                ->groupBy('user_id')->get();
            $names = User::whereIn('id', $rows->pluck('user_id'))->get(['id', 'name', 'avatar'])->keyBy('id');
            $stats = $rows->filter(fn ($r) => $names->has($r->user_id))
                ->map(fn ($r) => [
                    'name' => $names[$r->user_id]->name,
                    'avatar' => $names[$r->user_id]->avatar,
                    'total' => (int) $r->total,
                    'confirmed' => (int) $r->confirmed,
                    'sum' => (float) $r->confirmed_sum,
                    'actions' => [
                        ['label' => 'Участие', 'total' => (int) $r->p_total, 'won' => (int) $r->p_won],
                        ['label' => 'Звонок', 'total' => (int) $r->c_total, 'won' => (int) $r->c_won],
                        ['label' => 'КП', 'total' => (int) $r->o_total, 'won' => (int) $r->o_won],
                    ],
                ])->sortByDesc('confirmed')->values();
        }

        // Результат по действиям (руководству): из скольких лотов с каждым
        // действием вышли сделки и на какую сумму. Уважает фильтр месяца, но
        // НЕ фильтр действия — иначе блок показывал бы одну строку из трёх.
        $byAction = null;
        if ($lead) {
            $rows = PreDeal::query()
                ->when($companyId, fn ($qq, $c) => $qq->where('company_id', $c))
                ->when($mid ?? null, fn ($qq, $m) => $qq->where('user_id', $m))
                ->when(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m2 = $request->string('month')->toString()), function ($qq) use ($m2) {
                    $start = $m2.'-01';
                    $qq->whereDate('created_at', '>=', $start)
                        ->whereDate('created_at', '<=', \Illuminate\Support\Carbon::parse($start)->endOfMonth()->toDateString());
                })
                ->selectRaw("action, count(*) total,
                    sum(case when status = 'confirmed' then 1 else 0 end) won,
                    sum(case when status = 'confirmed' then contract_sum else 0 end) won_sum")
                ->groupBy('action')->get()->keyBy('action');

            $byAction = collect(PreDeal::ACTIONS)->map(function ($a) use ($rows) {
                $r = $rows->get($a);
                $total = (int) ($r->total ?? 0);
                $won = (int) ($r->won ?? 0);

                return [
                    'action' => $a,
                    'label' => PreDeal::ACTION_LABELS[$a],
                    'total' => $total,
                    'won' => $won,
                    'conversion' => $total > 0 ? round($won / $total * 100, 1) : 0.0,
                    'won_sum' => (float) ($r->won_sum ?? 0),
                ];
            })->values();
        }

        return Inertia::render('PreDeals/Index', [
            'byAction' => $byAction,
            'preDeals' => $q->limit(300)->get(),
            'minMargin' => PreDeal::minMargin(),
            'taxPercent' => (float) \App\Models\Setting::get('tax_percent', 3),
            'leadership' => $lead,
            'stats' => $stats,
            'managers' => $lead ? User::role('manager')->where('is_active', true)->ofCompany($companyId)->orderBy('name')->get(['id', 'name']) : [],
            'filters' => $request->only('manager', 'status', 'month', 'action'),
            'actionLabels' => PreDeal::ACTION_LABELS,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PreDeal $ignore = null): array
    {
        // Действие определяет форму: у звонка и КП только контакты, товар и
        // сумма договора; расчёт маржи (закуп, доставка, сборка, комиссия,
        // партнёр, № лота, срок тендера) есть только у «Участия».
        $action = in_array($request->input('action'), PreDeal::ACTIONS, true)
            ? (string) $request->input('action') : 'participation';
        $short = in_array($action, PreDeal::SHORT_ACTIONS, true);

        $rules = [
            // Не required: «Участие» — умолчание, не приславший действие лот
            // ведёт себя как раньше.
            'action' => ['nullable', \Illuminate\Validation\Rule::in(PreDeal::ACTIONS)],
            'comment' => ['nullable', 'string', 'max:255'],
            'bin' => ['nullable', 'string', 'max:40'],
            'customer' => ['nullable', 'string', 'max:255'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'client_phone' => ['nullable', 'string', 'max:40'],
            'product' => ['required', 'string', 'max:255'],
            'contract_sum' => ['required', 'numeric', 'min:1'],
        ];

        if (! $short) {
            $rules += [
                // Уникальный № лота: менеджеры не заводят один лот дважды
                // (при правке — без ложного срабатывания на самого себя).
                'lot_number' => ['nullable', 'string', 'max:100',
                    \Illuminate\Validation\Rule::unique('pre_deals', 'lot_number')->ignore($ignore?->id)],
                'tender_deadline' => ['nullable', 'date'],
                'purchase_price' => ['nullable', 'numeric', 'min:0'],
                'partner_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'delivery' => ['nullable', 'numeric', 'min:0'],
                'assembly' => ['nullable', 'numeric', 'min:0'],
                'commission' => ['nullable', 'numeric', 'min:0'],
            ];
        }

        $data = $request->validate($rules, [
            'lot_number.unique' => 'Такой № лота уже существует — этот лот уже внесён.',
        ]);

        // Пришедшие «лишние» поля короткой формы игнорируем: тип записи —
        // единственный источник правды о том, что у лота может быть заполнено.
        return $data + ['action' => $action];
    }

    /**
     * Быстрая проверка № лота ДО заполнения формы: занят ли номер, кем и когда —
     * менеджер не тратит время на ввод остальных полей. ignore — id правящегося
     * лота (свой номер при правке не считается занятым).
     */
    /**
     * Откат случайного «Выиграл ✓»: созданная сделка удаляется (soft, номер
     * освобождается хуками Deal), лот возвращается в «В работе». Разрешён
     * ТОЛЬКО пока по сделке нет движения — счетов/расходов/заказа цеха.
     */
    public function revert(Request $request, PreDeal $preDeal): RedirectResponse
    {
        $this->guardOwner($request, $preDeal);
        $deal = $preDeal->deal;
        if ($preDeal->status !== 'confirmed' || ! $deal) {
            return back()->with('error', 'Лот не подтверждён — возвращать нечего.');
        }
        // Авто-расходы, созданные из самого лота (🚚/🔧, «Из лота…», без нал/банк),
        // откату не мешают — удаляются вместе со сделкой. Блокируют только
        // РУЧНЫЕ расходы, счета и заказ цеха.
        $manualExpenses = $deal->expenses()
            ->where(fn ($q) => $q->whereNotIn('type', ['delivery', 'assembly'])
                ->orWhereNotNull('payment_method')
                ->orWhere('description', 'not like', 'Из лота%'))
            ->exists();
        if ($deal->invoices()->exists() || $manualExpenses || $deal->project()->exists()) {
            return back()->with('error', 'По сделке '.$deal->number.' уже есть счета/расходы/заказ цеха — откат невозможен, обратитесь к администратору.');
        }

        $deal->expenses()->where('description', 'like', 'Из лота%')->delete();
        $preDeal->update(['status' => 'new', 'deal_id' => null]);
        $deal->delete();

        return back()->with('success', 'Возвращено: сделка '.$deal->number.' удалена, лот снова «В работе».');
    }

    public function checkLot(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->guardAccess($request);
        $data = $request->validate([
            'lot_number' => ['required', 'string', 'max:100'],
            'ignore' => ['nullable', 'integer'],
        ]);

        $existing = PreDeal::where('lot_number', trim($data['lot_number']))
            ->when($data['ignore'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))
            ->with('user:id,name')->latest()->first();

        return response()->json([
            'exists' => (bool) $existing,
            'manager' => $existing?->user?->name,
            'date' => $existing?->created_at?->toDateString(),
            'status' => $existing?->status,
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
        $preDeal->update(PreDeal::calculate($this->validated($request, $preDeal)));

        return back()->with('success', 'Пересчитано.');
    }

    public function destroy(Request $request, PreDeal $preDeal): RedirectResponse
    {
        $this->guardOwner($request, $preDeal);
        $preDeal->delete();

        return back()->with('success', 'Предварительная сделка удалена.');
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

        // Всё создание — одной транзакцией с блокировкой лота: двойной клик по
        // «Выиграл» (или два параллельных запроса) без этого успевал прочитать
        // status=new оба раза и создавал ДВЕ сделки с двумя комплектами расходов.
        return \Illuminate\Support\Facades\DB::transaction(function () use ($preDeal, $numbers) {
            $locked = PreDeal::whereKey($preDeal->id)->lockForUpdate()->first();
            if ($locked->status === 'confirmed') {
                return back()->with('error', 'Лот уже подтверждён.');
            }

            return $this->createDealFromLot($locked, $numbers);
        });
    }

    /** Тело «Выиграл»: сделка + pending-расходы лота (вызывается под блокировкой). */
    private function createDealFromLot(PreDeal $preDeal, DealNumberService $numbers): RedirectResponse
    {
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
            // Доля партнёра лота переносится в сделку (только %, сумма — от суммы договора).
            'partner_pct' => $preDeal->partner_pct !== null && (float) $preDeal->partner_pct > 0
                ? $preDeal->partner_pct : null,
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
        // Доставка/грузчики и сборка из лота переносятся в сделку, чтобы не
        // вносить их второй раз, но в статусе «ждёт бухгалтера» (pending):
        // деньги физически ещё не потрачены, поэтому на маржу сделки и на
        // кассу они не влияют, пока бухгалтер не подтвердит их чеком
        // (просьба владельца 09.08.2026 — раньше подтверждались автоматом).
        foreach ([['delivery', '🚚 Доставка'], ['assembly', '🔧 '.\App\Support\CompanyTerms::assembly($companyId)]] as [$type, $label]) {
            $amount = (float) $preDeal->{$type};
            if ($amount > 0) {
                \App\Models\Expense::create([
                    'company_id' => $companyId,
                    'expenseable_type' => 'deal',
                    'expenseable_id' => $deal->id,
                    'type' => $type,
                    'amount' => $amount,
                    'date' => now()->toDateString(),
                    'description' => 'Из лота'.($preDeal->lot_number ? ' №'.$preDeal->lot_number : '').': '.$label,
                    'responsible_user_id' => $preDeal->user_id,
                    'status' => 'pending',
                ]);
            }
        }

        $preDeal->update(['status' => 'confirmed', 'deal_id' => $deal->id]);

        // «Выиграл» → сразу на страницу Сделки, где появилась новая сделка.
        return redirect()->route('deals.index')->with('success', 'Выиграл! Сделка '.$deal->number.' создана.');
    }

}
