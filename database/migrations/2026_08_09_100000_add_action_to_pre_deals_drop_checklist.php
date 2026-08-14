<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Предсделка получает ДЕЙСТВИЕ (звонок / КП в ватсап / участие) — тип записи,
 * который выбирается при создании и определяет форму: у звонка и КП только
 * контакты и сумма, у участия — полный расчёт маржи.
 *
 * Чек-лист предсделки убираем: логика оказалась другой (просьба владельца
 * 09.08.2026). Уходят и таблица пунктов, и колонка отметок.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_deals', function (Blueprint $table) {
            // participation — умолчание: так лот вёл себя до появления действий,
            // поэтому старые записи остаются «участием» и ничего не теряют.
            $table->string('action', 20)->default('participation')->after('user_id');
            $table->string('comment')->nullable()->after('action');
            $table->index('action');
        });

        Schema::table('pre_deals', function (Blueprint $table) {
            if (Schema::hasColumn('pre_deals', 'checks')) {
                $table->dropColumn('checks');
            }
        });

        Schema::dropIfExists('pre_deal_checklist_items');
    }

    public function down(): void
    {
        Schema::create('pre_deal_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('pre_deals', function (Blueprint $table) {
            $table->json('checks')->nullable();
            $table->dropIndex(['action']);
            $table->dropColumn(['action', 'comment']);
        });
    }
};
