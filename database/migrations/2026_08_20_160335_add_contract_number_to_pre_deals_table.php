<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** № договора на лоте: проверка «такой уже есть» предупреждает, но не блокирует. */
    public function up(): void
    {
        Schema::table('pre_deals', function (Blueprint $table) {
            $table->string('contract_number', 100)->nullable()->after('lot_number');
        });
    }

    public function down(): void
    {
        Schema::table('pre_deals', function (Blueprint $table) {
            $table->dropColumn('contract_number');
        });
    }
};
