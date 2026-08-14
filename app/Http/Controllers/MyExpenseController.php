<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Мои расходы» — личная страница сотрудника: что он подал бухгалтеру на оплату
 * и что ему выдали (аванс, долг).
 *
 * Показывает ТОЛЬКО свои записи и только расходы КОМПАНИИ (без сделок): деньги
 * по сделкам живут в карточке сделки, здесь бы они только путали.
 */
class MyExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('expense.create') || $user->can('expense.viewAny'), 403);

        $month = preg_match('/^\d{4}-\d{2}$/', $request->string('month')->toString())
            ? $request->string('month')->toString() : now()->format('Y-m');
        $start = $month.'-01';
        $end = Carbon::parse($start)->endOfMonth()->toDateString();

        // Мои заявки: расход компании (без сделки), который завёл я.
        $mine = Expense::whereNull('expenseable_type')
            ->where('responsible_user_id', $user->id)
            ->whereNull('employee_payout')
            ->whereDate('date', '>=', $start)->whereDate('date', '<=', $end)
            ->with('category:id,name')
            ->orderByDesc('date')->orderByDesc('id')
            ->get()
            ->map(fn ($e) => $this->row($e));

        // Мне выдано: аванс и долг — их заводит бухгалтер, но касаются они меня.
        $payouts = Expense::where('employee_id', $user->id)
            ->whereDate('date', '>=', $start)->whereDate('date', '<=', $end)
            ->orderByDesc('date')->orderByDesc('id')
            ->get()
            ->map(fn ($e) => $this->row($e) + [
                'payout' => $e->employee_payout === 'debt' ? 'Долг' : 'Аванс',
            ]);

        return Inertia::render('Finance/MyExpenses', [
            'month' => $month,
            'mine' => $mine->values(),
            'payouts' => $payouts->values(),
            'totals' => [
                'pending' => round($mine->where('status', 'pending')->sum('amount'), 2),
                'confirmed' => round($mine->where('status', 'confirmed')->sum('amount'), 2),
                'payouts' => round($payouts->sum('amount'), 2),
            ],
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Expense $e): array
    {
        return [
            'id' => $e->id,
            'date' => optional($e->date)->toDateString(),
            'amount' => (float) $e->amount,
            'description' => $e->description,
            'category' => $e->category?->name,
            'status' => $e->status,
            'payment_method' => $e->payment_method,
            'has_file' => (bool) $e->file_path,
            'confirmed_at' => optional($e->confirmed_at)->toDateString(),
        ];
    }
}
