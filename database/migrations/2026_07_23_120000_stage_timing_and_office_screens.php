<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1) Тайминг этапов цеха: project_stage_logs — когда заказ вошёл на этап,
 *    когда ушёл и сколько провёл (для таймера на канбане/ТВ и истории).
 *    Для текущих активных заказов открываем лог задним числом (updated_at).
 * 2) Экраны: kind = workshop | office («Офис» — сделки и лидеры менеджеров).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_stage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_stage_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stage_name');
            $table->dateTime('entered_at');
            $table->dateTime('left_at')->nullable();
            $table->unsignedBigInteger('duration_seconds')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'left_at']);
        });

        Schema::table('workshop_screens', function (Blueprint $table) {
            $table->string('kind', 20)->default('workshop')->after('workshop');
            $table->dropUnique(['company_id', 'workshop']);
            $table->unique(['company_id', 'workshop', 'kind']);
        });

        // Активные заказы: открываем лог текущего этапа задним числом.
        DB::table('projects')->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('project_stage_id')->get(['id', 'project_stage_id', 'updated_at'])
            ->each(function ($p) {
                $name = DB::table('project_stages')->where('id', $p->project_stage_id)->value('name') ?? '—';
                DB::table('project_stage_logs')->insert([
                    'project_id' => $p->id, 'project_stage_id' => $p->project_stage_id,
                    'stage_name' => $name, 'entered_at' => $p->updated_at ?? now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('workshop_screens', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'workshop', 'kind']);
            $table->dropColumn('kind');
            $table->unique(['company_id', 'workshop']);
        });
        Schema::dropIfExists('project_stage_logs');
    }
};
