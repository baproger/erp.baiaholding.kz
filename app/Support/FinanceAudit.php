<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\FinanceRecordDeleted;

/**
 * Контроль удаления финансовых данных: любое удаление (расход, поступление,
 * счёт, платёж, задолженность) уведомляет СЕО (admin) и директора.
 */
class FinanceAudit
{
    /**
     * @param  string|null  $url  куда вести по «Открыть» (см. linkTo);
     *                            null — на Финансы, как раньше
     */
    public static function notifyDeleted(string $what, ?string $url = null): void
    {
        $actor = auth()->user();
        User::where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'director']))
            ->when($actor, fn ($q) => $q->where('id', '!=', $actor->id))
            ->get()
            ->each(fn (User $u) => $u->notify(new FinanceRecordDeleted($what, $actor?->name ?? 'система', $url)));
    }

    /**
     * Ссылка на ХОЗЯИНА удалённой записи: самой записи уже нет, открывать
     * нечего — ведём туда, где видно, что пропало. Сделка → её карточка,
     * заказ цеха → заказ, всё остальное (поступление, долг компании) → null,
     * то есть Финансы.
     */
    public static function linkTo(mixed $entity): ?string
    {
        return match (true) {
            $entity instanceof \App\Models\Deal => route('deals.show', $entity->id, absolute: false),
            $entity instanceof \App\Models\Project => route('projects.show', $entity->id, absolute: false),
            default => null,
        };
    }
}
