<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Безопасность входа (23.09.2026): код входа от администратора (второй фактор),
 * доверенные устройства, журнал входов, отметка сессий для выхода «везде».
 *
 * Идемпотентно по каждой колонке и таблице: DDL в MySQL не откатывается, и
 * если прошлый запуск упал на середине (на проде 24.09 остались колонки users
 * без таблиц), повторный запуск доделывает недостающее, а не падает на
 * «Duplicate column».
 */
return new class extends Migration
{
    public function up(): void
    {
        // users: 4 колонки, каждая — только если её ещё нет.
        $columns = [
            // Одноразовый код входа: хранится только хеш, живёт 24 часа.
            'login_code_hash' => fn (Blueprint $t) => $t->string('login_code_hash')->nullable(),
            'login_code_expires_at' => fn (Blueprint $t) => $t->dateTime('login_code_expires_at')->nullable(),
            'login_code_issued_by' => fn (Blueprint $t) => $t->unsignedBigInteger('login_code_issued_by')->nullable(),
            // Меняется при смене пароля / сбросе устройств / отключении —
            // все сессии со старой отметкой завершаются (любой драйвер сессий).
            'security_stamp' => fn (Blueprint $t) => $t->string('security_stamp', 32)->nullable(),
        ];
        foreach ($columns as $name => $define) {
            if (! Schema::hasColumn('users', $name)) {
                Schema::table('users', fn (Blueprint $t) => $define($t));
            }
        }

        if (! Schema::hasTable('trusted_devices')) {
            Schema::create('trusted_devices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('token_hash', 64)->unique();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->dateTime('last_used_at')->nullable();
                // DATETIME, не TIMESTAMP: на проде MySQL отвергает TIMESTAMP NOT NULL
                // без default («Invalid default value», 24.09.2026).
                $table->dateTime('expires_at');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('login_logs')) {
            Schema::create('login_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('email', 255)->nullable();
                $table->string('result', 32)->index();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->dateTime('created_at')->useCurrent()->index();
                $table->index(['user_id', 'created_at']);
            });
        }

        // Внешние ключи — отдельно и терпимо: приложение на них не опирается
        // (устройства удаляет само, пользователи soft-delete), а на shared-хостинге
        // FK иногда не проходит из-за настроек таблиц. Ошибка — в журнал, не стоп.
        $this->tryForeignKey('trusted_devices', 'trusted_devices_user_id_foreign',
            fn (Blueprint $t) => $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete());
        $this->tryForeignKey('login_logs', 'login_logs_user_id_foreign',
            fn (Blueprint $t) => $t->foreign('user_id')->references('id')->on('users')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::dropIfExists('login_logs');
        Schema::dropIfExists('trusted_devices');
        Schema::table('users', function (Blueprint $table) {
            foreach (['login_code_hash', 'login_code_expires_at', 'login_code_issued_by', 'security_stamp'] as $c) {
                if (Schema::hasColumn('users', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }

    private function tryForeignKey(string $table, string $name, \Closure $define): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return; // sqlite (тесты): FK добавляются только при создании таблицы
        }
        $existing = collect(Schema::getForeignKeys($table))->pluck('name')->all();
        if (in_array($name, $existing, true)) {
            return;
        }
        try {
            Schema::table($table, fn (Blueprint $t) => $define($t));
        } catch (\Throwable $e) {
            report($e);
        }
    }
};
