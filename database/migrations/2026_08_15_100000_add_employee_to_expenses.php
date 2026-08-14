<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Расходы по сотрудникам (аванс, долг) получают ЯВНУЮ связь с человеком.
 *
 * Раньше сотрудник жил только строкой в описании («Аванс сотруднику: Бахытжан»),
 * а `responsible_user_id` означал у таких расходов «кому выдали», хотя у расхода
 * по сделке — «кто потратил». Одна колонка с двумя смыслами: имя устаревало при
 * переименовании, отфильтровать выплаты по человеку было нельзя.
 *
 * Источник (аванс/долг) тоже помечаем: в кассовой книге и на Финансах видно,
 * за что деньги, без разбора текста.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('responsible_user_id')
                ->constrained('users')->nullOnDelete();
            // advance | debt — откуда вырос расход по сотруднику.
            $table->string('employee_payout', 20)->nullable()->after('employee_id');
            $table->index(['employee_id', 'employee_payout']);
        });

        // Backfill: связи уже существуют с обратной стороны — у корректировки
        // и у долга хранится expense_id. Берём оттуда, а не парсим описание.
        foreach ([
            ['payroll_adjustments', 'advance', "type = 'advance'"],
            ['employee_debts', 'debt', '1 = 1'],
        ] as [$table, $payout, $where]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            DB::table($table)->whereNotNull('expense_id')->whereRaw($where)
                ->orderBy('id')->chunk(200, function ($rows) use ($payout) {
                    foreach ($rows as $row) {
                        DB::table('expenses')->where('id', $row->expense_id)
                            ->update(['employee_id' => $row->user_id, 'employee_payout' => $payout]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'employee_payout']);
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn('employee_payout');
        });
    }
};
