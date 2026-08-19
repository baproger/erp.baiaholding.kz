<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Задача закрыта АВТОМАТИЧЕСКИ (сделка перешла на «ЭСФ» и дальше),
     * а не человеком — на карточке показывается знак «авто».
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('auto_completed')->default(false)->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('auto_completed');
        });
    }
};
