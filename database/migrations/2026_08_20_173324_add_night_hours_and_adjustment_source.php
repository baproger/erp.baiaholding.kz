<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Раздельная ЗП и бонусы (правило от 20.08.2026):
     * — ночные часы: ночной час = дневная ставка × 1.5;
     * — источник аванса: из ЗП или из бонуса — каждый уменьшает свой итог.
     */
    public function up(): void
    {
        Schema::table('work_hours', function (Blueprint $table) {
            $table->decimal('night_hours', 6, 2)->nullable()->after('hours');
        });
        Schema::table('payroll_adjustments', function (Blueprint $table) {
            $table->string('source', 10)->default('salary')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('work_hours', function (Blueprint $table) {
            $table->dropColumn('night_hours');
        });
        Schema::table('payroll_adjustments', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
