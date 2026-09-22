<?php

namespace App\Http\Middleware;

use App\Models\LoginLog;
use App\Support\LoginSecurity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * На каждом запросе авторизованного сотрудника:
 *  1) отключённый (is_active=false) — выход немедленно, а не «до конца сессии»;
 *  2) отметка безопасности сессии не совпадает с пользовательской (сменили
 *     пароль / сбросили устройства) — выход;
 *  3) включён код входа, а сессия его не прошла — пускаем только на экран кода.
 */
class EnsureAccountSecurity
{
    /** Маршруты, доступные до ввода кода. */
    private const CODE_ROUTES = ['login.code', 'login.code.verify', 'login.code.email', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        // Модель, созданная в памяти без is_active (фабрика в тестах), — активна:
        // из БД поле приходит всегда (default true).
        $active = array_key_exists('is_active', $user->getAttributes()) ? (bool) $user->is_active : true;
        if (! $active || $user->trashed()) {
            LoginLog::record('disabled', $user, $request);

            return $this->logout($request, 'Учётная запись отключена. Обратитесь к администратору.');
        }

        $session = $request->session();
        if ((string) $session->get(LoginSecurity::SESSION_STAMP, '') !== (string) $user->security_stamp) {
            LoginLog::record('session_revoked', $user, $request);

            return $this->logout($request, 'Сессия завершена: пароль или устройства были сброшены. Войдите заново.');
        }

        if (LoginSecurity::codeRequired() && ! $session->get(LoginSecurity::SESSION_CODE_OK)) {
            if (LoginSecurity::isTrustedDevice($user, $request)) {
                $session->put(LoginSecurity::SESSION_CODE_OK, true);
            } elseif (! in_array($request->route()?->getName(), self::CODE_ROUTES, true)) {
                return redirect()->route('login.code');
            }
        }

        return $next($request);
    }

    private function logout(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
