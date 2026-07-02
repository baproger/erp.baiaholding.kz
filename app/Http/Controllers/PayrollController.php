<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayrollController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('payroll.view'), 403);

        $leadership = $user->hasAnyRole(['admin', 'director', 'financist']);
        $rate = ((float) Setting::get('bonus_percent', 10)) / 100;

        // Actual income (paid) per responsible manager.
        $incomeByUser = Payment::query()
            ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->join('deals', fn ($j) => $j->on('invoices.invoiceable_id', '=', 'deals.id')->where('invoices.invoiceable_type', 'deal'))
            ->groupBy('deals.responsible_user_id')
            ->selectRaw('deals.responsible_user_id as uid, SUM(payments.amount) as v')->pluck('v', 'uid');

        // Confirmed expenses per manager.
        $expenseByUser = Expense::query()
            ->join('deals', fn ($j) => $j->on('expenses.expenseable_id', '=', 'deals.id')->where('expenses.expenseable_type', 'deal'))
            ->where('expenses.status', 'confirmed')
            ->groupBy('deals.responsible_user_id')
            ->selectRaw('deals.responsible_user_id as uid, SUM(expenses.amount) as v')->pluck('v', 'uid');

        $closedByUser = Deal::where('status', 'closed')->whereNotNull('responsible_user_id')
            ->groupBy('responsible_user_id')->selectRaw('responsible_user_id as uid, count(*) as c')->pluck('c', 'uid');
        $totalByUser = Deal::whereNotNull('responsible_user_id')
            ->groupBy('responsible_user_id')->selectRaw('responsible_user_id as uid, count(*) as c')->pluck('c', 'uid');

        $uids = collect($incomeByUser->keys())
            ->merge($expenseByUser->keys())->merge($totalByUser->keys())->unique()->filter()->values();
        if (! $leadership) {
            $uids = $uids->filter(fn ($id) => (int) $id === $user->id)->values();
        }

        $names = User::whereIn('id', $uids)->pluck('name', 'id');
        $rows = $uids->map(function ($uid) use ($incomeByUser, $expenseByUser, $closedByUser, $totalByUser, $names, $rate) {
            $income = (float) ($incomeByUser[$uid] ?? 0);
            $expense = (float) ($expenseByUser[$uid] ?? 0);
            $net = $income - $expense;
            $bonus = $net > 0 ? round($net * $rate, 2) : 0.0;

            return [
                'user' => $names[$uid] ?? '—',
                'deals' => (int) ($totalByUser[$uid] ?? 0),
                'closed' => (int) ($closedByUser[$uid] ?? 0),
                'income' => $income,
                'expense' => $expense,
                'net' => $net,
                'bonus' => $bonus,
                'company' => round($net - $bonus, 2),
            ];
        })->sortByDesc('bonus')->values();

        return Inertia::render('Payroll/Index', [
            'rows' => $rows,
            'leadership' => $leadership,
            'rate' => $rate * 100,
            'totals' => [
                'net' => (float) $rows->sum('net'),
                'bonus' => (float) $rows->sum('bonus'),
                'company' => (float) $rows->sum('company'),
            ],
        ]);
    }
}
