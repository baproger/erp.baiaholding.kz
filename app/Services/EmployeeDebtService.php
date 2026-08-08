<?php

namespace App\Services;

use App\Models\EmployeeDebt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Долги сотрудников: сколько погашено, сколько осталось и сколько удержать
 * в этом месяце.
 *
 * Правило одно и оно жёсткое: долг гасится ТОЛЬКО из бонуса и не больше
 * фиксированного платежа в месяц. Оклад не трогается ни при каких условиях —
 * нет бонуса в этом месяце, значит удержания нет, остаток едет дальше.
 */
class EmployeeDebtService
{
    public function __construct(private PayrollService $payroll) {}

    /**
     * Открытые долги сотрудника, старые первыми: гасим по очереди, чтобы
     * первый выданный закрылся раньше.
     *
     * @return Collection<int, EmployeeDebt>
     */
    public function openFor(int $userId): Collection
    {
        return EmployeeDebt::with('payments')->where('user_id', $userId)
            ->whereNull('closed_at')->orderBy('date')->orderBy('id')->get()
            ->filter(fn ($d) => $d->remaining() > 0)->values();
    }

    /**
     * Что произойдёт с долгами сотрудника в этом месяце при таком бонусе:
     * [charge — сколько удержим, remaining_before, remaining_after, per_debt].
     *
     * Ничего не пишет в базу — этим же методом страница ЗП показывает план,
     * а месячная команда считает факт.
     *
     * @return array{charge: float, before: float, after: float, per_debt: array<int, array{id: int, take: float}>}
     */
    public function planFor(int $userId, string $month, float $bonusOfMonth): array
    {
        $debts = $this->openFor($userId);
        $before = round($debts->sum(fn ($d) => $d->remaining()), 2);
        // Бонус, ещё не разобранный другими долгами этого же сотрудника.
        $budget = max(0.0, round($bonusOfMonth, 2));
        $perDebt = [];

        foreach ($debts as $debt) {
            if ($budget <= 0) {
                break;
            }
            // Уже начислено за этот месяц (повторный прогон не удвоит списание).
            $already = (float) $debt->payments->firstWhere('month', $month)?->amount;
            $limit = max(0.0, (float) $debt->monthly_amount - $already);
            $take = round(min($limit, $debt->remaining(), $budget), 2);

            if ($take > 0) {
                $perDebt[] = ['id' => $debt->id, 'take' => $take];
                $budget = round($budget - $take, 2);
            }
        }

        $charge = round(array_sum(array_column($perDebt, 'take')), 2);

        return [
            'charge' => $charge,
            'before' => $before,
            'after' => round($before - $charge, 2),
            'per_debt' => $perDebt,
        ];
    }

    /**
     * Записать погашения за месяц по всем сотрудникам с открытыми долгами.
     * Идемпотентно: повторный запуск за тот же месяц не спишет второй раз
     * (уникальный ключ debt+month, суммируем к уже начисленному).
     *
     * @return int сколько сотрудников затронуто
     */
    public function chargeMonth(string $month): int
    {
        $bonuses = $this->payroll->bonusByUserForMonth($month);
        $userIds = EmployeeDebt::whereNull('closed_at')->distinct()->pluck('user_id');
        $touched = 0;

        foreach ($userIds as $userId) {
            $bonus = (float) ($bonuses[$userId] ?? 0);
            if ($bonus <= 0) {
                continue; // Нет бонуса — нечего удерживать, оклад не трогаем.
            }

            $plan = $this->planFor((int) $userId, $month, $bonus);
            if (! $plan['per_debt']) {
                continue;
            }

            DB::transaction(function () use ($plan, $month) {
                foreach ($plan['per_debt'] as $row) {
                    $debt = EmployeeDebt::with('payments')->lockForUpdate()->find($row['id']);
                    if (! $debt) {
                        continue;
                    }
                    $payment = $debt->payments()->firstOrNew(['month' => $month]);
                    $payment->amount = round((float) $payment->amount + $row['take'], 2);
                    $payment->save();

                    if ($debt->fresh()->remaining() <= 0) {
                        $debt->update(['closed_at' => now()]);
                    }
                }
            });
            $touched++;
        }

        return $touched;
    }
}
