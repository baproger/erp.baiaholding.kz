<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Правило от 16.09.2026: на «Логистике» сделку дальше пускает только завсклад
 * (роль supplier — Аманжол). Ставим гейт-задачу на logistics-этапы обеих
 * воронок — дальше работает штатный механизм гейтов (задача снабженцу при
 * входе, выход только после его галочки). Идемпотентно: только пустые гейты.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('deal_stages')
            ->where('stage_type', 'logistics')
            ->where(fn ($q) => $q->whereNull('gate_task_title')->orWhere('gate_task_title', ''))
            ->update([
                'gate_task_title' => 'Подтвердить получение товара на складе',
                'gate_task_role' => 'supplier',
                'gate_task_days' => 2,
            ]);
    }

    public function down(): void
    {
        // Гейт можно снять руками в Настройки → Этапы.
    }
};
