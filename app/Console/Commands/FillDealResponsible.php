<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\PreDeal;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * ТЗ 02.11.2026, п.1.3: у старых сделок без ответственного проставить автора —
 * менеджера, который внёс предсделку (лот), иначе того, кто создал сделку
 * (по журналу аудита). Уволенных не ставим. --dry-run только показывает.
 */
class FillDealResponsible extends Command
{
    protected $signature = 'deals:fill-responsible {--dry-run : Только показать, что будет изменено}';

    protected $description = 'Проставить ответственного (автора) сделкам, где он пустой';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $deals = Deal::whereNull('responsible_user_id')->orderBy('id')->get(['id', 'number', 'company_name', 'responsible_user_id']);
        if ($deals->isEmpty()) {
            $this->info('Сделок без ответственного нет.');

            return self::SUCCESS;
        }

        $fromLots = PreDeal::whereIn('deal_id', $deals->pluck('id'))->whereNotNull('user_id')->pluck('user_id', 'deal_id');
        $fromAudit = AuditLog::where('table_name', 'deals')->where('action', 'created')
            ->whereIn('record_id', $deals->pluck('id'))->whereNotNull('user_id')
            ->orderBy('id')->get(['record_id', 'user_id'])->unique('record_id')->pluck('user_id', 'record_id');
        $people = User::whereIn('id', $fromLots->values()->merge($fromAudit->values())->unique())->get(['id', 'name', 'status'])->keyBy('id');

        $rows = [];
        $toFill = 0;
        foreach ($deals as $deal) {
            $uid = $fromLots[$deal->id] ?? $fromAudit[$deal->id] ?? null;
            $source = isset($fromLots[$deal->id]) ? 'предсделка' : (isset($fromAudit[$deal->id]) ? 'создал (аудит)' : '—');
            $user = $uid ? $people->get($uid) : null;
            $note = '';
            if (! $user) {
                $note = 'автор не найден — назначьте вручную';
                $uid = null;
            } elseif ($user->isFired()) {
                $note = 'автор уволен — назначьте вручную';
                $uid = null;
            }
            $rows[] = [$deal->number, mb_strimwidth((string) $deal->company_name, 0, 40, '…'), $user?->name ?? '—', $source, $note ?: ($dry ? 'будет проставлен' : 'проставлен')];
            if ($uid) {
                $toFill++;
                if (! $dry) {
                    $deal->update(['responsible_user_id' => $uid]); // через модель — попадёт в Историю
                }
            }
        }

        $this->table(['Сделка', 'Компания', 'Ответственный', 'Источник', 'Итог'], $rows);
        $this->info(($dry ? '[dry-run] Будет проставлено: ' : 'Проставлено: ').$toFill.' из '.$deals->count().'.');

        return self::SUCCESS;
    }
}
