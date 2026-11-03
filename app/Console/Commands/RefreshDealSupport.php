<?php

namespace App\Console\Commands;

use App\Models\Deal;
use App\Services\DealSupportService;
use Illuminate\Console\Command;

/**
 * Пересчёт «Сопровождения сделки» (ТЗ 02.11.2026, п.6) — раз в сутки для
 * проверок по времени (просрочка, «ждёт > 48 ч», просроченный долг).
 * Обычные изменения пересчитываются сразу по событиям.
 */
class RefreshDealSupport extends Command
{
    protected $signature = 'deals:support-refresh {--all : И закрытые/отменённые тоже}';

    protected $description = 'Пересчитать статус «Сопровождение сделки» (🟢/🟡/🔴)';

    public function handle(DealSupportService $support): int
    {
        $counts = ['green' => 0, 'yellow' => 0, 'red' => 0];
        Deal::query()->when(! $this->option('all'), fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->orderBy('id')->select('id')->chunkById(100, function ($deals) use ($support, &$counts) {
                foreach ($deals as $d) {
                    $status = $support->refresh($d->id);
                    if ($status) {
                        $counts[$status]++;
                    }
                }
            });
        $this->info("🟢 {$counts['green']} · 🟡 {$counts['yellow']} · 🔴 {$counts['red']}");

        return self::SUCCESS;
    }
}
