<?php

namespace App\Services;

use App\Models\EmployeeDebt;
use App\Models\EmployeeDebtPayment;
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
     * Долги сотрудника, относящиеся к месяцу: открытые + те, что этим месяцем
     * и закрылись (иначе полностью погашенный долг пропал бы из ведомости
     * вместе со своим удержанием).
     *
     * @return Collection<int, EmployeeDebt>
     */
    public function forMonth(int $userId, string $month): Collection
    {
        return EmployeeDebt::with('payments')->where('user_id', $userId)
            ->where(fn ($q) => $q->whereNull('closed_at')
                ->orWhereHas('payments', fn ($p) => $p->where('month', $month)))
            ->orderBy('date')->orderBy('id')->get();
    }

    /**
     * Что происходит с долгами сотрудника в этом месяце при таком бонусе.
     *
     * charge — сколько месяц стоит сотруднику ВСЕГО: уже удержанное командой
     * (charged) плюс то, что ещё предстоит (planned). Без этой суммы ведомость
     * показывала бы 0 сразу после прогона cron, и «К выплате» прыгало бы.
     *
     * Ничего не пишет в базу: этим же методом страница ЗП показывает план,
     * а месячная команда — считает факт (ей нужен только per_debt).
     *
     * @return array{charge: float, charged: float, planned: float, before: float, after: float, per_debt: array<int, array{id: int, take: float}>}
     */
    public function planFor(int $userId, string $month, float $bonusOfMonth): array
    {
        $debts = $this->openFor($userId);
        // Уже удержано за месяц — по ВСЕМ долгам, включая закрытые этим месяцем.
        $charged = round((float) EmployeeDebtPayment::whereIn(
            'employee_debt_id', EmployeeDebt::where('user_id', $userId)->select('id')
        )->where('month', $month)->sum('amount'), 2);

        $openRemaining = round($debts->sum(fn ($d) => $d->remaining()), 2);
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

        $planned = round(array_sum(array_column($perDebt, 'take')), 2);

        return [
            // Сколько месяц стоит сотруднику всего — факт + план.
            'charge' => round($charged + $planned, 2),
            'charged' => $charged,
            'planned' => $planned,
            // Долг на начало месяца и на конец.
            'before' => round($openRemaining + $charged, 2),
            'after' => round($openRemaining - $planned, 2),
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
