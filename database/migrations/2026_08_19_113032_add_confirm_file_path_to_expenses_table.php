<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Два чека у расхода: file_path — заявка/чек МЕНЕДЖЕРА при создании,
     * confirm_file_path — чек ОПЛАТЫ бухгалтера при подтверждении.
     * Так их можно сравнить рядом на карточке сделки.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('confirm_file_path')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('confirm_file_path');
        });
    }
};
