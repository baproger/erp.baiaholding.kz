<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Прод-фикс 25.08.2026: у части этапов сделок не проставлен stage_type
 * (он назначается вручную в Настройки → Этапы), из-за чего дизайнер и
 * снабженец не видели свои сделки («Дизайн и расчет» — BAIA). Проставляем
 * тип по названию этапа — только там, где тип пуст и не занят другим
 * этапом той же воронки. Ничего не перезаписываем.
 */
return new class extends Migration
{
    public function up(): void
    {
        $map = [
            'design' => ['дизайн'],
            'shop_gate' => ['закуп'],
            'contract' => ['заключение договора'],
            'logistics' => ['логистика'],
            'assembly' => ['сборка', 'работа'],
            'act' => ['акт'],
            'esf' => ['эсф'],
            'payment_won' => ['тендер закрыт'],
        ];

        $companyIds = DB::table('deal_stages')->distinct()->pluck('company_id');
        foreach ($companyIds as $companyId) {
            $byCompany = fn () => DB::table('deal_stages')
                ->when($companyId === null,
                    fn ($q) => $q->whereNull('company_id'),
                    fn ($q) => $q->where('company_id', $companyId));

            $taken = $byCompany()->whereNotNull('stage_type')->pluck('stage_type')->all();
            $empty = $byCompany()->whereNull('stage_type')->orderBy('order')->get(['id', 'name']);

            foreach ($map as $type => $needles) {
                if (in_array($type, $taken, true)) {
                    continue;
                }
                foreach ($empty as $i => $stage) {
                    $name = mb_strtolower($stage->name);
                    foreach ($needles as $needle) {
                        if (str_contains($name, $needle)) {
                            DB::table('deal_stages')->where('id', $stage->id)->update(['stage_type' => $type]);
                            $empty->forget($i);
                            continue 3;
                        }
                    }
                }
            }
        }
    }

    public function down(): void
    {
        // Откат не нужен: миграция лишь дополняет пустые значения.
    }
};
