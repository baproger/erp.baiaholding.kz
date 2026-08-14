<?php

namespace App\Http\Controllers;

use App\Models\CashReceipt;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Касса — кассовая книга за день, как бумажный «Отчёт кассира»:
 * остаток на начало → операции по времени → остаток на конец.
 *
 * Только НАЛИЧНЫЕ и ЕДИНЫЕ на холдинг: наличные физически лежат в одной кассе,
 * поэтому расход налом любой фирмы уменьшает общий остаток (то же правило, что
 * у плитки «Касса» на Финансах — цифры обязаны сходиться).
 *
 * Остаток на начало дня считается НА ЛЕТУ (всё, что было до этого дня), а не
 * фиксируется закрытием: закрытия дня в системе нет, и лишняя дисциплина
 * бухгалтеру сейчас не нужна.
 */
class CashBookController extends Controller
{
    public function index(Request $request): Response
    {
        // Деньги видит руководство — как страница Финансы.
        abort_unless($request->user()->hasAnyRole(['admin', 'director', 'financist']), 403);

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->string('date')->toString())
            ? $request->string('date')->toString() : now()->toDateString();
        $day = Carbon::parse($date);

        // Наличные ЕДИНЫЕ на холдинг, банк — РАЗДЕЛЬНЫЙ по фирмам: в банковской
        // книге сужаемся до текущей компании, в кассовой — нет.
        $kind = in_array($request->string('kind')->toString(), ['bank', 'all'], true)
            ? $request->string('kind')->toString() : 'cash';
        // «Общее» = наличные + банк, каждый по своему правилу: касса едина на
        // холдинг, банк берётся у текущей фирмы. Смешивать правила нельзя —
        // цифры перестали бы сходиться с Финансами.
        $kinds = $kind === 'all' ? ['cash', 'bank'] : [$kind];
        $scope = fn (string $k) => $k === 'bank' ? (\App\Support\CurrentCompany::id() ?: null) : null;

        $opening = round(collect($kinds)->sum(fn ($k) => $this->balanceBefore($day, $k, $scope($k))), 2);
        $operations = collect($kinds)
            ->flatMap(fn ($k) => $this->operationsOf($day, $k, $scope($k))
                ->map(fn ($op) => $op + ['method' => $k]))
            ->sortBy('at')->values();

        $income = round($operations->where('kind', 'in')->sum('amount'), 2);
        $expense = round($operations->where('kind', 'out')->sum('amount'), 2);

        // Промежуточный баланс у каждой строки: видно, каким остаток был после
        // каждой операции — как в бумажной книге.
        $running = $opening;
        $operations = $operations->map(function ($op) use (&$running) {
            $running = round($running + ($op['kind'] === 'in' ? $op['amount'] : -$op['amount']), 2);
            $op['balance'] = $running;

            return $op;
        });

        return Inertia::render('Finance/CashBook', [
            'date' => $date,
            'kind' => $kind,
            'opening' => $opening,
            'income' => $income,
            'expense' => $expense,
            'closing' => round($opening + $income - $expense, 2),
            'operations' => $operations->values(),
            // Итог кассы «за всё время» — сверка с плиткой на Финансах.
            'liveBalance' => round(collect($kinds)->sum(
                fn ($k) => app(\App\Services\FinanceService::class)
                    ->companyBalances(\App\Support\CurrentCompany::id() ?: null)[$k] ?? 0
            ), 2),
        ]);
    }

    /**
     * Остаток кассы на утро указанного дня: всё движение наличных ДО него.
     * Ручная корректировка кассы (инвентаризация) даты не имеет, поэтому
     * относится к прошлому и входит в остаток на начало.
     */
    private function balanceBefore(Carbon $day, string $kind, ?int $companyId): float
    {
        $before = $day->toDateString();

        $in = (float) $this->payments($kind, $companyId)
            ->whereDate('payment_date', '<', $before)->sum('amount');
        $in += (float) $this->receipts($kind, $companyId)
            ->whereDate('date', '<', $before)->sum('amount');

        $out = (float) $this->expenses($kind, $companyId)
            ->whereDate('date', '<', $before)->sum('amount');

        // Ручная корректировка (инвентаризация) есть только у кассы и даты не
        // имеет, поэтому относится к прошлому.
        $correction = $kind === 'cash' ? (float) Setting::get('cash_correction', 0) : 0.0;

        return round($in - $out + $correction, 2);
    }

    /**
     * Оплаты по счетам выбранным способом. Банк = всё, что не «нал» (включая
     * незаполненный способ) — то же правило, что в FinanceService.
     */
    private function payments(string $kind, ?int $companyId)
    {
        $q = Payment::query();
        $kind === 'cash'
            ? $q->where('payment_method', 'cash')
            : $q->where(fn ($w) => $w->where('payment_method', '!=', 'cash')->orWhereNull('payment_method'));

        return $q->when($companyId, fn ($qq, $c) => $qq->whereHas('invoice', fn ($i) => $i
            ->where('invoiceable_type', 'deal')
            ->whereIn('invoiceable_id', \App\Models\Deal::where('company_id', $c)->select('id'))));
    }

    private function receipts(string $kind, ?int $companyId)
    {
        return CashReceipt::where('method', $kind === 'cash' ? 'cash' : 'bank')
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c));
    }

    private function expenses(string $kind, ?int $companyId)
    {
        $q = Expense::where('status', 'confirmed');
        $kind === 'cash'
            ? $q->where('payment_method', 'cash')
            : $q->where('payment_method', '!=', 'cash')->whereNotNull('payment_method');

        return $q->when($companyId, fn ($qq, $c) => $qq->where('company_id', $c));
    }

    /**
     * Операции дня по трём источникам наличных, в хронологическом порядке.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function operationsOf(Carbon $day, string $kind, ?int $companyId): \Illuminate\Support\Collection
    {
        $on = $day->toDateString();

        $payments = $this->payments($kind, $companyId)->whereDate('payment_date', $on)
            ->with('invoice:id,number,invoiceable_type,invoiceable_id')
            ->get(['id', 'invoice_id', 'amount', 'payment_date', 'reference', 'note', 'created_at'])
            ->map(fn ($p) => [
                'id' => 'pay-'.$p->id,
                'kind' => 'in',
                'amount' => (float) $p->amount,
                'title' => 'Оплата по счёту '.($p->invoice?->number ?? '—'),
                'note' => $p->note ?: $p->reference,
                'tag' => 'Оплата сделки',
                'employee' => null,
                'payout' => null,
                'deal_id' => $p->invoice?->invoiceable_type === 'deal' ? $p->invoice->invoiceable_id : null,
                'at' => optional($p->created_at)->toIso8601String(),
            ]);

        $receipts = $this->receipts($kind, $companyId)->whereDate('date', $on)
            ->get(['id', 'amount', 'source', 'note', 'date', 'created_at'])
            ->map(fn ($r) => [
                'id' => 'rec-'.$r->id,
                'kind' => 'in',
                'amount' => (float) $r->amount,
                'title' => $r->source ?: ($kind === 'cash' ? 'Поступление в кассу' : 'Поступление на счёт'),
                'note' => $r->note,
                'tag' => 'Поступление',
                'employee' => null,
                'payout' => null,
                'deal_id' => null,
                'at' => optional($r->created_at)->toIso8601String(),
            ]);

        $expenses = $this->expenses($kind, $companyId)->whereDate('date', $on)
            ->with(['category:id,name', 'employee:id,name'])
            ->get(['id', 'amount', 'description', 'category_id', 'type', 'expenseable_type', 'expenseable_id',
                'employee_id', 'employee_payout', 'date', 'created_at'])
            ->map(fn ($e) => [
                'id' => 'exp-'.$e->id,
                'kind' => 'out',
                'amount' => (float) $e->amount,
                'title' => $e->description ?: 'Расход',
                'note' => null,
                // Тег: категория расхода компании либо вид расхода сделки.
                'tag' => $e->category?->name ?? match ($e->type) {
                    'delivery' => 'Доставка',
                    'purchase' => 'Закуп',
                    'assembly' => 'Сборка',
                    default => 'Расход',
                },
                'deal_id' => $e->expenseable_type === 'deal' ? $e->expenseable_id : null,
                // Выплата сотруднику: кому и за что — ссылкой, а не текстом
                // в описании (имя там устаревает при переименовании).
                'employee' => $e->employee ? ['id' => $e->employee->id, 'name' => $e->employee->name] : null,
                'payout' => match ($e->employee_payout) {
                    'advance' => 'Аванс',
                    'debt' => 'Долг',
                    default => null,
                },
                'at' => optional($e->created_at)->toIso8601String(),
            ]);

        return $payments->concat($receipts)->concat($expenses)
            ->sortBy('at')->values();
    }
}
