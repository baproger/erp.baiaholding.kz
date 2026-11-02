<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Смета дизайнера (ТЗ 02.11.2026, п.2): у документа-сметы есть сумма.
 * Без сметы сделка не уходит с «Дизайн и расчет». Идемпотентно.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('documents', 'amount')) {
            Schema::table('documents', fn (Blueprint $t) => $t->decimal('amount', 14, 2)->nullable()->after('kind'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('documents', 'amount')) {
            Schema::table('documents', fn (Blueprint $t) => $t->dropColumn('amount'));
        }
    }
};
