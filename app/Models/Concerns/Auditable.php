<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Support\Arr;

trait Auditable
{
    /**
     * Attributes never worth auditing.
     *
     * @var array<int, string>
     */
    protected array $auditExclude = ['updated_at', 'created_at', 'deleted_at', 'remember_token', 'password'];

    public static function bootAuditable(): void
    {
        // Снимок ВСЕЙ записи при создании и удалении: иначе в Аудите видно
        // лишь «что-то произошло с записью #116», а что именно — нет, и
        // удалённые деньги не восстановить даже глазами.
        static::created(fn ($model) => $model->writeAudit(
            'created', AuditLog::SNAPSHOT, null, $model->auditSnapshot()
        ));
        static::deleted(fn ($model) => $model->writeAudit(
            'deleted', AuditLog::SNAPSHOT, $model->auditSnapshot(), null
        ));
        static::updated(function ($model) {
            foreach ($model->getChanges() as $field => $new) {
                // Смену пароля фиксируем как факт (кто и когда) — значения
                // маскируем: хэши в журнале не нужны и опасны.
                if ($field === 'password') {
                    $model->writeAudit('updated', 'password', '•••', '•••');

                    continue;
                }
                if (in_array($field, $model->auditExclude, true)) {
                    continue;
                }
                $model->writeAudit('updated', $field, Arr::get($model->getOriginal(), $field), $new);
            }
        });
    }

    /**
     * Снимок записи для аудита: все поля, кроме служебных и секретов.
     * Пустые значения выбрасываем — в отчёте о том, ЧТО удалили, десяток
     * прочерков только мешает читать.
     *
     * @return array<string, mixed>
     */
    protected function auditSnapshot(): array
    {
        return collect($this->getAttributes())
            ->except(array_merge($this->auditExclude, ['id']))
            ->reject(fn ($v) => $v === null || $v === '' || $v === [])
            ->map(fn ($v) => is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE))
            ->all();
    }

    protected function writeAudit(string $action, ?string $field = null, $old = null, $new = null): void
    {
        $request = request();

        AuditLog::create([
            'user_id' => auth()->id(),
            'ip' => $request?->ip(),
            'user_agent' => substr((string) $request?->userAgent(), 0, 255),
            'table_name' => $this->getTable(),
            'record_id' => $this->getKey(),
            'action' => $action,
            'field_name' => $field,
            // JSON_UNESCAPED_UNICODE: иначе русские названия в снимке
            // превращаются в \uXXXX и отчёт нечитаем.
            'old_value' => is_scalar($old) || $old === null ? $old : json_encode($old, JSON_UNESCAPED_UNICODE),
            'new_value' => is_scalar($new) || $new === null ? $new : json_encode($new, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }
}
