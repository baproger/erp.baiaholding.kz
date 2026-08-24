<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Журнал ошибок сайта (правило от 22.08.2026): каждая серверная ошибка
     * пишется сюда и видна ТОЛЬКО админу на странице Аудит → Ошибки.
     * Удаления не существует — ни маршрута, ни кнопки.
     */
    public function up(): void
    {
        Schema::create('error_logs', function (Blueprint $table) {
            $table->id();
            $table->string('exception', 191);      // класс исключения
            $table->text('message');
            $table->string('file', 255)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('url', 512)->nullable();
            $table->string('method', 10)->nullable();
            $table->foreignId('user_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('trace')->nullable();     // верхушка стека
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_logs');
    }
};
