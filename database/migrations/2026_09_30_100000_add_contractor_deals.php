<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Подрядные сделки (правило владельца от 30.09.2026): бухгалтер заводит сделку,
 * работу делает подрядчик, компания оставляет себе % — остальное перечисляет
 * подрядчику. Идемпотентно по колонкам (DDL в MySQL не откатывается).
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            // own — своя сделка (из предсделки), contractor — подрядная.
            'kind' => fn (Blueprint $t) => $t->string('kind', 16)->default('own')->index(),
            'contractor_name' => fn (Blueprint $t) => $t->string('contractor_name')->nullable(),
            // Наш процент от суммы договора; подрядчику уходит остальное.
            'commission_pct' => fn (Blueprint $t) => $t->decimal('commission_pct', 5, 2)->nullable(),
        ];
        foreach ($columns as $name => $define) {
            if (! Schema::hasColumn('deals', $name)) {
                Schema::table('deals', fn (Blueprint $t) => $define($t));
            }
        }
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            foreach (['kind', 'contractor_name', 'commission_pct'] as $c) {
                if (Schema::hasColumn('deals', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
