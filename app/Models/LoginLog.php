<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/**
 * Журнал входов: кто, когда, откуда и чем закончилось. Не удаляется
 * (как журнал ошибок) — при инциденте видно, заходил ли кто-то под чужой учёткой.
 */
class LoginLog extends Model
{
    public const UPDATED_AT = null;

    public const RESULTS = [
        'success' => 'Вход',
        'failed_password' => 'Неверный пароль',
        'disabled' => 'Отключённая учётная запись',
        'code_ok' => 'Код входа принят',
        'failed_code' => 'Неверный код входа',
        'code_locked' => 'Блокировка по коду',
        'code_issued' => 'Выпущен код входа',
        'code_emailed' => 'Код отправлен на почту',
        'devices_revoked' => 'Сброшены устройства',
        'session_revoked' => 'Сессия завершена',
        'lockout' => 'Блокировка по паролю',
    ];

    protected $fillable = ['user_id', 'email', 'result', 'ip', 'user_agent', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $result, ?User $user, ?Request $request = null, ?string $email = null): void
    {
        $request ??= request();
        static::create([
            'user_id' => $user?->id,
            'email' => mb_substr($email ?? $user?->email ?? '', 0, 255) ?: null,
            'result' => $result,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            'created_at' => now(),
        ]);
    }
}
