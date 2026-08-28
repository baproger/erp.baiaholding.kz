<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Вид документа: 'estimate' — смета дизайнера по сделке (правило от
 * 25.08.2026). Бухгалтер сверяет заявки на материалы со склада со сметой.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
