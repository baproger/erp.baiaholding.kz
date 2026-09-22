<?php

namespace App\Support;

use App\Models\LoginLog;
use App\Models\Setting;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Код входа от администратора (второй фактор), доверенные устройства и
 * принудительное завершение сессий. Правило владельца от 23.09.2026:
 * админ выпускает код сотруднику внутри системы, сотрудник после логина и
 * пароля вводит код; устройство запоминается на 30 дней.
 *
 * Поля безопасности пишутся через query builder, а не через save(): они
 * не должны попадать в журнал аудита (хеш кода) и не должны дёргать события.
 */
final class LoginSecurity
{
    public const CODE_TTL_HOURS = 24;

    public const DEVICE_TTL_DAYS = 30;

    public const COOKIE = 'baia_device';

    public const SESSION_STAMP = 'security_stamp';

    public const SESSION_CODE_OK = 'login_code_verified';

    /** Включается в Настройках после того, как коды выпущены (по умолчанию выкл.). */
    public static function codeRequired(): bool
    {
        return (bool) Setting::get('require_login_code', false);
    }

    /** Выпустить одноразовый код (6 цифр). Возвращает код в открытом виде — показать админу один раз. */
    public static function issueCode(User $user, ?User $issuedBy, ?Request $request = null): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        self::updateSecurity($user, [
            'login_code_hash' => Hash::make($code),
            'login_code_expires_at' => now()->addHours(self::CODE_TTL_HOURS),
            'login_code_issued_by' => $issuedBy?->id,
        ]);
        LoginLog::record('code_issued', $user, $request);

        return $code;
    }

    public static function verifyCode(User $user, string $code): bool
    {
        if (! $user->login_code_hash || ! $user->login_code_expires_at || $user->login_code_expires_at->isPast()) {
            return false;
        }
        if (! Hash::check($code, $user->login_code_hash)) {
            return false;
        }
        self::updateSecurity($user, ['login_code_hash' => null, 'login_code_expires_at' => null, 'login_code_issued_by' => null]);

        return true;
    }

    /** Есть ли у сотрудника действующий (не просроченный) код. */
    public static function hasActiveCode(User $user): bool
    {
        return (bool) $user->login_code_hash && $user->login_code_expires_at && $user->login_code_expires_at->isFuture();
    }

    /** Запомнить устройство: токен в cookie (шифруется Laravel), в БД — только хеш. */
    public static function trustDevice(User $user, Request $request): void
    {
        $token = Str::random(48);
        TrustedDevice::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            'last_used_at' => now(),
            'expires_at' => now()->addDays(self::DEVICE_TTL_DAYS),
        ]);
        Cookie::queue(Cookie::make(
            self::COOKIE, $user->id.'|'.$token, self::DEVICE_TTL_DAYS * 24 * 60,
            '/', null, (bool) config('session.secure'), true, false, 'lax'
        ));
    }

    public static function isTrustedDevice(User $user, Request $request): bool
    {
        $raw = (string) $request->cookie(self::COOKIE, '');
        [$id, $token] = array_pad(explode('|', $raw, 2), 2, '');
        if ((int) $id !== $user->id || $token === '') {
            return false;
        }
        $device = TrustedDevice::where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();
        if (! $device) {
            return false;
        }
        if (! $device->last_used_at || $device->last_used_at->lt(now()->subHour())) {
            $device->forceFill(['last_used_at' => now()])->save();
        }

        return true;
    }

    /** Забыть все устройства и завершить все сессии: при следующем входе снова нужен код. */
    public static function revokeDevices(User $user, ?Request $request = null): void
    {
        TrustedDevice::where('user_id', $user->id)->delete();
        self::bumpStamp($user);
        LoginLog::record('devices_revoked', $user, $request);
    }

    /** Отключение сотрудника: устройства, код, сессии — всё обнуляется. */
    public static function revokeAccess(User $user, ?Request $request = null): void
    {
        TrustedDevice::where('user_id', $user->id)->delete();
        self::updateSecurity($user, ['login_code_hash' => null, 'login_code_expires_at' => null, 'login_code_issued_by' => null]);
        self::bumpStamp($user);
        LoginLog::record('session_revoked', $user, $request);
    }

    /**
     * Новая отметка безопасности: все сессии со старой отметкой завершатся
     * (EnsureAccountSecurity), «запомнить меня» на других устройствах тоже.
     */
    public static function bumpStamp(User $user, bool $keepCurrentSession = false): void
    {
        $stamp = Str::random(32);
        self::updateSecurity($user, ['security_stamp' => $stamp, 'remember_token' => Str::random(60)]);
        if ($keepCurrentSession && request()->hasSession()) {
            request()->session()->put(self::SESSION_STAMP, $stamp);
        }
    }

    /** Отметить текущую сессию как принадлежащую текущей отметке пользователя. */
    public static function stampSession(User $user, Request $request): void
    {
        $request->session()->put(self::SESSION_STAMP, (string) $user->security_stamp);
    }

    /** @param array<string, mixed> $fields */
    private static function updateSecurity(User $user, array $fields): void
    {
        User::withTrashed()->whereKey($user->id)->update($fields);
        foreach ($fields as $k => $v) {
            $user->setAttribute($k, $v);
        }
        $user->syncOriginal();
    }
}
