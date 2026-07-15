<?php

namespace App\Http\Controllers;

use App\Models\PayrollAdjustment;
use App\Models\Setting;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PayrollController extends Controller
{
    /** Корректировки и оклад вводит только бухгалтер (financist) или админ. */
    private function canManage(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'financist']);
    }

    public function index(Request $request, PayrollService $payroll): Response
    {
        $user = $request->user();
        abort_unless($user->can('payroll.view'), 403);

        $leadership = $user->hasAnyRole(['admin', 'director', 'financist']);
        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;

        // Месяц корректировок (отгулы/больничные/штрафы/премии): YYYY-MM.
        $month = preg_match('/^\d{4}-\d{2}$/', $request->string('month')->toString())
            ? $request->string('month')->toString() : now()->format('Y-m');
        $monthStart = $month.'-01';
        $monthEnd = \Illuminate\Support\Carbon::parse($monthStart)->endOfMonth()->toDateString();

        $adjustments = PayrollAdjustment::with('creator:id,name')
            ->whereDate('date', '>=', $monthStart)->whereDate('date', '<=', $monthEnd)
            ->orderBy('date')->get()->groupBy('user_id');

        // Single source of truth for the payroll math (shared with Analytics & Finance).
        $rows = $payroll->perUser()->sortByDesc('bonus')->values();
        if (! $leadership) {
            $rows = $rows->filter(fn ($r) => $r['uid'] === $user->id)->values();
        }

        // Per-deal breakdown so a row can expand into the employee's «Оплата успешно»
        // and «Акт утверждение» deals — the raw data the financist needs to check ЗП.
        $breakdown = $payroll->dealBreakdown();
        $rows = $rows->map(function ($r) use ($breakdown, $adjustments) {
            $r['dealsList'] = array_values(($breakdown->get($r['uid']) ?? collect())->all());
            $adj = $adjustments->get($r['uid']) ?? collect();
            $deductions = round((float) $adj->whereIn('type', PayrollAdjustment::DEDUCTIONS)->sum('amount'), 2);
            $additions = round((float) $adj->where('type', 'bonus')->sum('amount'), 2);
            $r['adjustments'] = $adj->map(fn ($a) => [
                'id' => $a->id, 'type' => $a->type, 'days' => $a->days !== null ? (float) $a->days : null,
                'amount' => (float) $a->amount, 'date' => optional($a->date)->toDateString(),
                'note' => $a->note, 'creator' => $a->creator?->name,
            ])->values();
            $r['deductions'] = $deductions;
            $r['additions'] = $additions;
            // К выплате = оклад + бонус − удержания + премии.
            $r['final'] = round($r['payout'] - $deductions + $additions, 2);

            return $r;
        });

        return Inertia::render('Payroll/Index', [
            'rows' => $rows,
            'leadership' => $leadership,
            'canManage' => $this->canManage($request),
            'month' => $month,
            'taxRate' => $taxRate * 100,
            'totals' => [
                'budget' => (float) $rows->sum('budget'),
                'tax' => (float) $rows->sum('tax'),
                'expense' => (float) $rows->sum('expense'),
                'bonus' => (float) $rows->sum('bonus'),
                'salary' => (float) $rows->sum('salary'),
                'payout' => (float) $rows->sum('payout'),
                'deductions' => (float) $rows->sum('deductions'),
                'additions' => (float) $rows->sum('additions'),
                'final' => (float) $rows->sum('final'),
                'company' => (float) $rows->sum('company'),
            ],
        ]);
    }

    /**
     * Корректировка ЗП: отгул/больничный — можно днями (сумма = оклад/22 × дни),
     * штраф/премия — суммой. Только бухгалтер/админ.
     */
    public function storeAdjustment(Request $request): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Корректировки вводит бухгалтер или админ.');

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'type' => ['required', Rule::in(PayrollAdjustment::TYPES)],
            'days' => ['nullable', 'numeric', 'min:0.5', 'max:31'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // Автосумма для отгула/больничного: оклад / 22 рабочих дня × дни.
        if (empty($data['amount']) && ! empty($data['days']) && in_array($data['type'], ['absence', 'sick'], true)) {
            $salary = (float) (User::find($data['user_id'])->salary ?? 0);
            $data['amount'] = round($salary / 22 * (float) $data['days'], 2);
        }
        if (empty($data['amount']) || (float) $data['amount'] <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'amount' => 'Укажите сумму (или дни — для отгула/больничного при заполненном окладе).',
            ]);
        }

        $data['created_by'] = $request->user()->id;
        PayrollAdjustment::create($data);

        return back()->with('success', 'Корректировка добавлена.');
    }

    public function destroyAdjustment(Request $request, PayrollAdjustment $adjustment): RedirectResponse
    {
        abort_unless($this->canManage($request), 403);
        $adjustment->delete();

        return back()->with('success', 'Корректировка удалена.');
    }

    /** Оклад вводит бухгалтер/админ прямо в ведомости. */
    public function updateSalary(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Оклад вводит бухгалтер или админ.');

        $data = $request->validate(['salary' => ['required', 'numeric', 'min:0', 'max:99999999']]);
        $user->update(['salary' => $data['salary']]);

        return back()->with('success', 'Оклад обновлён.');
    }
}
