<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\LoginSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Увольнение и восстановление сотрудника (правило владельца от 07.10.2026).
 *
 * Сотрудник больше не удаляется: статус fired + дата. Вход закрыт, сессии и
 * доверенные устройства сброшены, новые дела ему не назначаются, но вся
 * история (сделки, заказы, задачи, ЗП, бонусы, аудит) остаётся с его именем —
 * прибыль компании за прошлые периоды не меняется задним числом.
 */
class EmployeeStatusService
{
    /**
     * Уволить. Передача дел — по желанию: без преемника ничего не переназначается.
     *
     * @return array{deals:int, projects:int, tasks:int} сколько дел передано
     */
    public function fire(User $user, Carbon $firedAt, ?string $note, ?User $successor, ?Request $request = null): array
    {
        $moved = ['deals' => 0, 'projects' => 0, 'tasks' => 0];

        DB::transaction(function () use ($user, $firedAt, $note, $successor, &$moved) {
            $user->update([
                'status' => User::STATUS_FIRED,
                'fired_at' => $firedAt->toDateString(),
                'fired_note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
                'is_active' => false,
            ]);

            if ($successor) {
                // Через update() каждой записи — переназначение попадает в Аудит.
                foreach (self::openDealsQuery()->where('responsible_user_id', $user->id)->get() as $deal) {
                    $deal->update(['responsible_user_id' => $successor->id]);
                    $moved['deals']++;
                }
                foreach (self::openProjectsQuery()->where('responsible_user_id', $user->id)->get() as $project) {
                    $project->update(['responsible_user_id' => $successor->id]);
                    $moved['projects']++;
                }
                foreach (self::openTasksQuery()->where('assignee_id', $user->id)->get() as $task) {
                    $task->update(['assignee_id' => $successor->id]);
                    $moved['tasks']++;
                }
            }
        });

        LoginSecurity::revokeAccess($user, $request);
        self::forgetCaches($user);

        return $moved;
    }

    /** Восстановить (вернулся на работу) — история цела, вход снова открыт. */
    public function restore(User $user): void
    {
        $user->update([
            'status' => User::STATUS_WORKING,
            'is_active' => true,
            'fired_at' => null,
            'fired_note' => null,
        ]);
        self::forgetCaches($user);
    }

    /** Открытые сделки: не закрыты (не в цехе) и не на won-этапе. */
    public static function openDealsQuery()
    {
        return Deal::query()->whereNotIn('status', ['closed', 'cancelled'])
            ->whereNotIn('deal_stage_id', DealStage::where('is_won', true)->select('id'));
    }

    public static function openProjectsQuery()
    {
        return Project::query()->where('status', 'active');
    }

    public static function openTasksQuery()
    {
        return Task::query()->where('status', '!=', 'done');
    }

    /**
     * Мягко удалённых — в уволенные (миграция 2026_10_07). Query builder без
     * событий модели: не плодить записи аудита и не дёргать кеши/уведомления.
     */
    public static function restoreSoftDeleted(): int
    {
        $count = 0;
        User::onlyTrashed()->get(['id', 'deleted_at'])->each(function ($u) use (&$count) {
            $count += User::withTrashed()->whereKey($u->id)->update([
                'status' => User::STATUS_FIRED,
                'fired_at' => $u->deleted_at ? Carbon::parse($u->deleted_at)->toDateString() : now()->toDateString(),
                'is_active' => false,
                'deleted_at' => null,
            ]);
        });
        if ($count > 0) {
            Cache::forget('dict.User');
            Cache::forget('audit.users');
        }

        return $count;
    }

    private static function forgetCaches(User $user): void
    {
        foreach (['dict.User', 'audit.users', 'user_companies.'.$user->id, 'user_company_ids.'.$user->id] as $key) {
            Cache::forget($key);
        }
    }
}
