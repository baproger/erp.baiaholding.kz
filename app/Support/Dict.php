<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Справочники id→имя для подписей в аудите/истории. Раньше каждая карточка
 * сделки/заказа и страница Аудита делали полные выборки users/stages —
 * теперь кеш на 5 минут (имена меняются редко; сброс не критичен).
 */
final class Dict
{
    public static function users(): \Illuminate\Support\Collection
    {
        return self::names(\App\Models\User::class);
    }

    public static function dealStages(): \Illuminate\Support\Collection
    {
        return self::names(\App\Models\DealStage::class);
    }

    public static function projectStages(): \Illuminate\Support\Collection
    {
        return self::names(\App\Models\ProjectStage::class);
    }

    /** @param class-string<\Illuminate\Database\Eloquent\Model> $model */
    public static function names(string $model): \Illuminate\Support\Collection
    {
        // В кеш — чистый массив (Collection после файлового кеша возвращалась битой).
        return collect(Cache::remember('dict.'.class_basename($model), 300, fn () => $model::pluck('name', 'id')->all()));
    }
}
