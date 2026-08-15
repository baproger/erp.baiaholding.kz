<?php

namespace App\Support;

use Illuminate\Notifications\DatabaseNotification;

/**
 * Гасит красный счётчик, когда действие из уведомления уже ВЫПОЛНЕНО:
 * расход подтверждён/удалён, задача закрыта — висящее «непрочитанное»
 * больше не требует ничего, помечаем его прочитанным у всех получателей.
 */
class NotificationResolver
{
    /** Расход подтверждён или удалён — «Расход ждёт подтверждения» неактуально. */
    public static function expense(int $expenseId): void
    {
        DatabaseNotification::whereNull('read_at')
            ->where('data->expense_id', $expenseId)
            ->update(['read_at' => now()]);
    }

    /** Задача закрыта (вручную или гейтом) — «Вам назначена задача»/«просрочена» неактуальны. */
    public static function task(int $taskId): void
    {
        DatabaseNotification::whereNull('read_at')
            ->where('data->task_id', $taskId)
            ->update(['read_at' => now()]);
    }

    /** @param  iterable<int>  $taskIds */
    public static function tasks(iterable $taskIds): void
    {
        foreach ($taskIds as $id) {
            self::task((int) $id);
        }
    }
}
