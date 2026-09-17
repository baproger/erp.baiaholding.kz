<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Уточнение владельца от 17.09.2026: гейт завсклада на «Логистике» — только
 * для BAIA. Снимаем наш дефолтный гейт с logistics-этапов остальных компаний
 * (ручные настройки с другим названием не трогаем). Идемпотентно.
 */
return new class extends Migration
{
    public function up(): void
    {
        $baiaId = DB::table('companies')->where('code', 'BAIA')->value('id');

        DB::table('deal_stages')
            ->where('stage_type', 'logistics')
            ->when($baiaId, fn ($q) => $q->where(fn ($w) => $w
                ->where('company_id', '!=', $baiaId)->orWhereNull('company_id')))
            ->where('gate_task_title', 'Подтвердить получение товара на складе')
            ->update(['gate_task_title' => null, 'gate_task_role' => null, 'gate_task_days' => null]);
    }

    public function down(): void
    {
        // Обратно гейт ставится в Настройки → Этапы.
    }
};
