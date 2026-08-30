<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'language' => $user->language,
                    // CEO видит всё как admin: для ролевых массивов фронта добавляем 'admin'
                    // (первая роль остаётся 'ceo' — подпись в шапке «СЕО»).
                    'roles' => ($rn = $user->getRoleNames())->contains('ceo') ? $rn->push('admin')->unique()->values() : $rn,
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ] : null,
                // Firms the user may work in + the one currently selected (header switcher).
                // Фирмы пользователя меняются редко — кэш на минуту снимает запрос с каждого перехода.
                'companies' => $user
                    ? \Illuminate\Support\Facades\Cache::remember('user_companies.'.$user->id, 60,
                        fn () => $user->companies()->where('is_active', true)->orderBy('name')->get(['companies.id', 'name', 'code'])->toArray())
                    : [],
                'currentCompanyId' => $user ? \App\Support\CurrentCompany::id() : null,
            ],
            // Уведомления шапки — два запроса к БД на КАЖДЫЙ переход каждого
            // сотрудника. Кэш на 15 секунд снимает эту нагрузку почти целиком;
            // при новом уведомлении/прочтении кэш сбрасывается (NotificationCache).
            'notifications' => fn () => $user
                ? \Illuminate\Support\Facades\Cache::remember('notif_head.'.$user->id, 300, fn () => [
                    'unread' => $user->unreadNotifications()->count(),
                    'items' => $user->notifications()->latest()->limit(10)->get()
                        ->map(fn ($n) => [
                            'id' => $n->id,
                            'data' => $n->data,
                            'read_at' => $n->read_at,
                            'created_at' => $n->created_at,
                        ])->values()->all(),
                ])
                : ['unread' => 0, 'items' => []],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'locale' => app()->getLocale(),
            'translations' => fn () => \App\Models\UiTranslation::map(app()->getLocale()),
            // Публичный VAPID-ключ Web Push: фронт подписывает браузер на пуши чата.
            'vapidPublicKey' => (string) config('services.webpush.public_key', ''),
        ];
    }
}
