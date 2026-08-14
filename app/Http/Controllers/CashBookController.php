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

        $opening = $this->balanceBefore($day);
        $operations = $this->operationsOf($day);

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
            'opening' => $opening,
            'income' => $income,
            'expense' => $expense,
            'closing' => round($opening + $income - $expense, 2),
            'operations' => $operations->values(),
            // Итог кассы «за всё время» — сверка с плиткой на Финансах.
            'liveBalance' => round(app(\App\Services\FinanceService::class)->companyBalances(\App\Support\CurrentCompany::id() ?: null)['cash'] ?? 0, 2),
        ]);
    }

    /**
     * Остаток кассы на утро указанного дня: всё движение наличных ДО него.
     * Ручная корректировка кассы (инвентаризация) даты не имеет, поэтому
     * относится к прошлому и входит в остаток на начало.
     */
    private function balanceBefore(Carbon $day): float
    {
        $before = $day->toDateString();

        $in = (float) Payment::where('payment_method', 'cash')
            ->whereDate('payment_date', '<', $before)->sum('amount');
        $in += (float) CashReceipt::where('method', 'cash')
            ->whereDate('date', '<', $before)->sum('amount');

        $out = (float) Expense::where('status', 'confirmed')->where('payment_method', 'cash')
            ->whereDate('date', '<', $before)->sum('amount');

        return round($in - $out + (float) Setting::get('cash_correction', 0), 2);
    }

    /**
     * Операции дня по трём источникам наличных, в хронологическом порядке.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function operationsOf(Carbon $day): \Illuminate\Support\Collection
    {
        $on = $day->toDateString();

        $payments = Payment::where('payment_method', 'cash')->whereDate('payment_date', $on)
            ->with('invoice:id,number,invoiceable_type,invoiceable_id')
            ->get(['id', 'invoice_id', 'amount', 'payment_date', 'reference', 'note', 'created_at'])
            ->map(fn ($p) => [
                'id' => 'pay-'.$p->id,
                'kind' => 'in',
                'amount' => (float) $p->amount,
                'title' => 'Оплата по счёту '.($p->invoice?->number ?? '—'),
                'note' => $p->note ?: $p->reference,
                'tag' => 'Оплата сделки',
                'deal_id' => $p->invoice?->invoiceable_type === 'deal' ? $p->invoice->invoiceable_id : null,
                'at' => optional($p->created_at)->toIso8601String(),
            ]);

        $receipts = CashReceipt::where('method', 'cash')->whereDate('date', $on)
            ->get(['id', 'amount', 'source', 'note', 'date', 'created_at'])
            ->map(fn ($r) => [
                'id' => 'rec-'.$r->id,
                'kind' => 'in',
                'amount' => (float) $r->amount,
                'title' => $r->source ?: 'Поступление в кассу',
                'note' => $r->note,
                'tag' => 'Поступление',
                'deal_id' => null,
                'at' => optional($r->created_at)->toIso8601String(),
            ]);

        $expenses = Expense::where('status', 'confirmed')->where('payment_method', 'cash')
            ->whereDate('date', $on)
            ->with('category:id,name')
            ->get(['id', 'amount', 'description', 'category_id', 'type', 'expenseable_type', 'expenseable_id', 'date', 'created_at'])
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
                'at' => optional($e->created_at)->toIso8601String(),
            ]);

        return $payments->concat($receipts)->concat($expenses)
            ->sortBy('at')->values();
    }
}
