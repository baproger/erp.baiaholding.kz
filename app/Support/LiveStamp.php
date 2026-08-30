<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * «Штамп изменений» пользователя — правило от 29.08.2026: живые обновления
 * без WebSocket и без SQL на каждый опрос. События моделей (сообщение чата,
 * уведомление, задача) сдвигают штамп в кеше; фронт раз в 30–300 с читает
 * /live/version (одно чтение кеша, 304 при совпадении ETag) и перезагружает
 * только то, что изменилось.
 */
final class LiveStamp
{
    public const KEYS = ['chat', 'notifications', 'tasks'];

    /** @return array{chat:int, notifications:int, tasks:int} */
    public static function get(int $userId): array
    {
        return Cache::get("live:$userId") ?? ['chat' => 0, 'notifications' => 0, 'tasks' => 0];
    }

    /** @param int|array<int|null>|\Illuminate\Support\Collection|null $userIds */
    public static function bump(int|array|\Illuminate\Support\Collection|null $userIds, string $what): void
    {
        if (! in_array($what, self::KEYS, true)) {
            return;
        }
        $ids = collect(is_iterable($userIds) ? $userIds : [$userIds])->filter()->map(fn ($v) => (int) $v)->unique();
        foreach ($ids as $id) {
            $stamp = self::get($id);
            $stamp[$what] = (int) (microtime(true) * 1000);
            Cache::put("live:$id", $stamp, 86400 * 7);
        }
    }
}
