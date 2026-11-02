<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Доп. срок сделки (ТЗ 02.11.2026, п.5): исходный «Срок» по договору не
 * перезаписывается; продление — отдельное поле с обязательной причиной,
 * не дальше 60 дней от исходного срока. Идемпотентно; date, не timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'extended_deadline' => fn (Blueprint $t) => $t->date('extended_deadline')->nullable()->index(),
            'extension_reason' => fn (Blueprint $t) => $t->string('extension_reason', 500)->nullable(),
            'extensions_count' => fn (Blueprint $t) => $t->unsignedSmallInteger('extensions_count')->default(0),
        ];
        foreach ($columns as $name => $define) {
            if (! Schema::hasColumn('deals', $name)) {
                Schema::table('deals', fn (Blueprint $t) => $define($t));
            }
        }
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            foreach (['extended_deadline', 'extension_reason', 'extensions_count'] as $c) {
                if (Schema::hasColumn('deals', $c)) {
                    if ($c === 'extended_deadline') {
                        $t->dropIndex(['extended_deadline']);
                    }
                    $t->dropColumn($c);
                }
            }
        });
    }
};
