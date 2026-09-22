<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Безопасность входа (23.09.2026): код входа от администратора (второй фактор),
 * доверенные устройства, журнал входов, отметка сессий для выхода «везде».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Одноразовый код входа: хранится только хеш, живёт 24 часа.
            $table->string('login_code_hash')->nullable()->after('remember_token');
            $table->timestamp('login_code_expires_at')->nullable()->after('login_code_hash');
            $table->unsignedBigInteger('login_code_issued_by')->nullable()->after('login_code_expires_at');
            // Меняется при смене пароля / сбросе устройств / отключении —
            // все сессии со старой отметкой завершаются (любой драйвер сессий).
            $table->string('security_stamp', 32)->nullable()->after('login_code_issued_by');
        });

        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('login_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 255)->nullable();
            $table->string('result', 32)->index();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_logs');
        Schema::dropIfExists('trusted_devices');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['login_code_hash', 'login_code_expires_at', 'login_code_issued_by', 'security_stamp']);
        });
    }
};
