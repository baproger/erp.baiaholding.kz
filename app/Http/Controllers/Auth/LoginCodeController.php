<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginLog;
use App\Support\LoginSecurity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Экран «Введите код входа» после логина и пароля. Код выпускает администратор
 * (Сотрудники → «Код входа»); 5 неверных попыток — блокировка на 15 минут.
 */
class LoginCodeController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const LOCK_SECONDS = 900;

    public function show(Request $request): Response|RedirectResponse
    {
        if (! LoginSecurity::codeRequired() || $request->session()->get(LoginSecurity::SESSION_CODE_OK)) {
            return redirect()->intended(route('dashboard', absolute: false));
        }
        $user = $request->user();

        return Inertia::render('Auth/LoginCode', [
            'name' => $user->name,
            'hasCode' => LoginSecurity::hasActiveCode($user),
            // Страховка от самоблокировки: админ может получить код на свою почту
            // (только если почта на сервере настроена по-настоящему).
            'canEmail' => $this->canEmail($request),
            'status' => session('status'),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $key = 'login-code:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            LoginLog::record('code_locked', $user, $request);
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages(['code' => "Слишком много попыток. Попробуйте через {$minutes} мин."]);
        }

        if (! LoginSecurity::verifyCode($user, $data['code'])) {
            RateLimiter::hit($key, self::LOCK_SECONDS);
            LoginLog::record('failed_code', $user, $request);
            throw ValidationException::withMessages(['code' => 'Неверный или просроченный код. Запросите новый у администратора.']);
        }

        RateLimiter::clear($key);
        LoginSecurity::trustDevice($user, $request);
        $request->session()->put(LoginSecurity::SESSION_CODE_OK, true);
        LoginLog::record('code_ok', $user, $request);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function email(Request $request): RedirectResponse
    {
        abort_unless($this->canEmail($request), 403);
        $user = $request->user();
        $code = LoginSecurity::issueCode($user, $user, $request);
        try {
            $user->notify(new \App\Notifications\LoginCodeMail($code));
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['code' => 'Не удалось отправить письмо. Обратитесь к другому администратору.']);
        }
        LoginLog::record('code_emailed', $user, $request);

        return back()->with('status', 'Код отправлен на '.$this->maskEmail($user->email).' — действует 24 часа.');
    }

    private function canEmail(Request $request): bool
    {
        return $request->user()->hasRole('admin')
            && ! in_array(config('mail.default'), ['log', 'array', null], true);
    }

    private function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 2).'***@'.$domain;
    }
}
