<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Статус сотрудника «Работает / Уволен» вместо удаления (правило владельца
 * от 07.10.2026). Удалённый (soft-delete) сотрудник выпадал из всех связей:
 * сделки «не назначен», аудит без имён, ЗП и бонусы исчезали задним числом.
 * Идемпотентно по колонкам (DDL в MySQL не откатывается); date, не timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'status' => fn (Blueprint $t) => $t->string('status', 16)->default('working')->index(),
            'fired_at' => fn (Blueprint $t) => $t->date('fired_at')->nullable(),
            'fired_note' => fn (Blueprint $t) => $t->string('fired_note', 255)->nullable(),
        ];
        foreach ($columns as $name => $define) {
            if (! Schema::hasColumn('users', $name)) {
                Schema::table('users', fn (Blueprint $t) => $define($t));
            }
        }

        // Ранее «удалённые» — возвращаются как уволенные: их история снова на месте.
        \App\Services\EmployeeStatusService::restoreSoftDeleted();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['status', 'fired_at', 'fired_note'] as $c) {
                if (Schema::hasColumn('users', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
