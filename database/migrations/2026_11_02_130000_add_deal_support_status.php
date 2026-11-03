<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Сопровождение сделки» (ТЗ 02.11.2026, п.6): итоговый статус хранится в
 * сделке — список и фильтр «красных» не пересчитывают 12 проверок на каждую
 * загрузку. Отметки «Логистика/Сборка не требуется» — для проверки «Этап без
 * расхода». Идемпотентно; dateTime, не timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'support_status' => fn (Blueprint $t) => $t->string('support_status', 8)->nullable()->index(), // green | yellow | red
            'support_checked_at' => fn (Blueprint $t) => $t->dateTime('support_checked_at')->nullable(),
            'logistics_not_required' => fn (Blueprint $t) => $t->boolean('logistics_not_required')->default(false),
            'assembly_not_required' => fn (Blueprint $t) => $t->boolean('assembly_not_required')->default(false),
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
            foreach (['support_status', 'support_checked_at', 'logistics_not_required', 'assembly_not_required'] as $c) {
                if (Schema::hasColumn('deals', $c)) {
                    if ($c === 'support_status') {
                        $t->dropIndex(['support_status']);
                    }
                    $t->dropColumn($c);
                }
            }
        });
    }
};
