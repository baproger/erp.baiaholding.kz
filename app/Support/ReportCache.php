<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Кеш тяжёлых страниц (Сводный отчёт, Аналитика, Зарплата, Бонусы) — правило
 * от 29.08.2026. Страница считается один раз и живёт TTL секунд; любое
 * изменение «денежной» модели (см. AppServiceProvider::boot) сдвигает версию,
 * и все кеши отчётов протухают разом — устаревших цифр не бывает.
 *
 * Ключ: отчёт + версия + пользователь (менеджер видит только себя) +
 * ФИРМА (BAIA/ASU живёт в сессии, а не в query!) + язык + фильтры.
 * Права проверяются ДО обращения к кешу — в контроллере.
 */
final class ReportCache
{
    public const TTL = 300;

    /** @param \Closure(): array<string, mixed> $build @return array<string, mixed> */
    public static function remember(Request $request, string $report, \Closure $build): array
    {
        $key = implode(':', [
            'report', $report, self::version(),
            (string) $request->user()?->id,
            (string) (CurrentCompany::id() ?? 0),
            app()->getLocale(),
            md5(json_encode($request->query())),
        ]);

        return Cache::remember($key, self::TTL, $build);
    }

    /** Сдвинуть версию: вызывается событиями моделей, влияющих на цифры. */
    public static function bump(): void
    {
        Cache::put('report:version', (int) (microtime(true) * 1000), 86400 * 30);
    }

    public static function version(): int
    {
        return (int) Cache::get('report:version', 0);
    }
}
