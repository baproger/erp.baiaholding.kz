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
        // 'report2': смена префикса 31.08.2026 — старые записи с «живыми»
        // коллекциями внутри игнорируются (см. нормализацию ниже).
        $key = implode(':', [
            'report2', $report, self::version(),
            (string) $request->user()?->id,
            (string) (CurrentCompany::id() ?? 0),
            app()->getLocale(),
            md5(json_encode($request->query())),
        ]);

        // В кеш — ТОЛЬКО чистые массивы. Eloquent/Support-коллекции после
        // serialize/unserialize из файлового кеша возвращались как
        // __PHP_Incomplete_Class → json_encode делал из массива объект {} и
        // Сводный отчёт падал белым экраном (прод, 31.08.2026). Прогон через
        // json нормализует всё ровно так, как это ушло бы в браузер.
        $value = Cache::remember($key, self::TTL, fn () => json_decode(json_encode($build()), true));

        return is_array($value) ? $value : json_decode(json_encode($build()), true);
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
