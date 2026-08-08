<?php

namespace App\Console\Commands;

use App\Services\EmployeeDebtService;
use Illuminate\Console\Command;

/**
 * Ежемесячное погашение долгов сотрудников из бонусов. По расписанию идёт
 * 1-го числа за ПРОШЛЫЙ месяц — когда его бонусы уже начислены целиком.
 *
 * Идемпотентна: повторный запуск за тот же месяц не спишет второй раз, так что
 * её безопасно догонять руками (`php artisan debts:charge --month=2026-07`).
 */
class ChargeEmployeeDebts extends Command
{
    protected $signature = 'debts:charge {--month= : Месяц YYYY-MM (по умолчанию — прошлый)}';

    protected $description = 'Погасить долги сотрудников из бонусов за месяц';

    public function handle(EmployeeDebtService $debts): int
    {
        $month = $this->option('month') ?: now()->subMonthNoOverflow()->format('Y-m');

        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error('Месяц указывается как YYYY-MM, например 2026-07.');

            return self::FAILURE;
        }

        $touched = $debts->chargeMonth($month);
        $this->info("Долги за {$month}: затронуто сотрудников — {$touched}.");

        return self::SUCCESS;
    }
}
