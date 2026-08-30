<?php

namespace App\Providers;

use App\Models\Deal;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Прод за HTTPS: генерим только https-ссылки (mixed content / редиректы).
        if ($this->app->isProduction()) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        Vite::prefetch(concurrency: 3);

        // Кеш отчётов (ReportCache): любое изменение модели, влияющей на
        // цифры, сдвигает версию — все кешированные отчёты протухают разом.
        foreach ([
            Deal::class, \App\Models\DealStage::class, \App\Models\PreDeal::class,
            \App\Models\Payment::class, \App\Models\Invoice::class, \App\Models\Expense::class,
            \App\Models\PayrollAdjustment::class, \App\Models\WorkHour::class,
            \App\Models\EmployeeDebt::class, \App\Models\EmployeeDebtPayment::class,
            \App\Models\CashReceipt::class, \App\Models\DdsEntry::class, \App\Models\Debt::class,
            \App\Models\Setting::class, User::class, Project::class,
        ] as $model) {
            $model::saved(fn () => \App\Support\ReportCache::bump());
            $model::deleted(fn () => \App\Support\ReportCache::bump());
        }

        // Живые обновления без WebSocket (LiveStamp): события двигают штамп
        // пользователя, фронт опрашивает /live/version — см. useLive.js.
        \App\Models\ChatMessage::saved(fn ($m) => \App\Support\LiveStamp::bump(
            $m->chat?->participants()->pluck('users.id'), 'chat'));
        \App\Models\ChatMessage::deleted(fn ($m) => \App\Support\LiveStamp::bump(
            $m->chat?->participants()->pluck('users.id'), 'chat'));
        \App\Models\Task::saved(fn ($t) => \App\Support\LiveStamp::bump(
            [$t->assignee_id, $t->creator_id, $t->getOriginal('assignee_id')], 'tasks'));
        \App\Models\Task::deleted(fn ($t) => \App\Support\LiveStamp::bump([$t->assignee_id, $t->creator_id], 'tasks'));
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Notifications\Events\NotificationSent::class,
            \App\Listeners\BumpLiveStampOnNotification::class,
        );

        // Admins bypass all policy/permission checks.
        Gate::before(fn (User $user, string $ability) => $user->hasRole('admin') ? true : null);

        // Stable polymorphic aliases used across tasks/documents/comments/etc.
        Relation::enforceMorphMap([
            'deal' => Deal::class,
            'project' => Project::class,
            'user' => User::class,
        ]);
    }
}
