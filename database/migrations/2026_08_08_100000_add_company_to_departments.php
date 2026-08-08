<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Отделы становятся своими у каждой фирмы (BAIA/ASU) — до этого «Отдел продаж»
 * был один на холдинг и списки/фильтры отделов смешивали сотрудников обеих фирм.
 *
 * code — общий ключ одного и того же отдела в разных фирмах («Отдел продаж» BAIA
 * и «Отдел продаж» ASU имеют один code): по нему сотрудник, работающий в обеих
 * фирмах, попадает в одноимённый отдел в секции каждой фирмы.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
            $table->string('code', 64)->nullable()->after('name');
            $table->index(['company_id', 'is_active']);
        });

        $companies = DB::table('companies')->where('is_active', true)
            ->orderBy('id')->pluck('id')->all();

        if (! $companies) {
            return;
        }

        $primary = $companies[0];
        // Отделы с отметкой удаления тоже дублируем — иначе «удалённый» отдел
        // воскреснет во второй фирме как активный.
        $existing = DB::table('departments')->whereNull('company_id')->get();

        foreach ($existing as $dept) {
            $code = 'dept'.$dept->id;
            DB::table('departments')->where('id', $dept->id)
                ->update(['company_id' => $primary, 'code' => $code]);

            foreach (array_slice($companies, 1) as $companyId) {
                $copyId = DB::table('departments')->insertGetId([
                    'company_id' => $companyId,
                    'name' => $dept->name,
                    'code' => $code,
                    'description' => $dept->description,
                    // Руководитель наследуется только если он работает в этой фирме.
                    'head_user_id' => $this->headForCompany($dept->head_user_id, $companyId),
                    'is_active' => $dept->is_active,
                    'created_at' => $dept->created_at,
                    'updated_at' => now(),
                    'deleted_at' => $dept->deleted_at,
                ]);

                // Членство (department_user) — сотрудник остаётся в отделе той
                // фирмы, в которой работает; работающий в обеих числится в обеих.
                $memberIds = DB::table('department_user')
                    ->where('department_id', $dept->id)->pluck('user_id');

                foreach ($memberIds as $userId) {
                    $inCompany = DB::table('company_user')
                        ->where('user_id', $userId)->where('company_id', $companyId)->exists();

                    if ($inCompany) {
                        DB::table('department_user')->insertOrIgnore([
                            'department_id' => $copyId,
                            'user_id' => $userId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                // Основной отдел сотрудника переезжает в копию, если в головной
                // фирме он не работает (например, цеховой только в BAIA).
                DB::table('users')->where('department_id', $dept->id)
                    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('company_user')
                        ->whereColumn('company_user.user_id', 'users.id')
                        ->where('company_user.company_id', $primary))
                    ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('company_user')
                        ->whereColumn('company_user.user_id', 'users.id')
                        ->where('company_user.company_id', $companyId))
                    ->update(['department_id' => $copyId]);
            }

            // Осиротевшее членство в отделе головной фирмы (сотрудник в ней не
            // работает) убираем — иначе он висел бы в чужой фирме.
            DB::table('department_user')->where('department_id', $dept->id)
                ->whereNotIn('user_id', DB::table('company_user')
                    ->where('company_id', $primary)->pluck('user_id')->all() ?: [0])
                ->delete();
        }
    }

    public function down(): void
    {
        // Копии отделов вторых фирм схлопываем обратно в отдел головной фирмы.
        $companies = DB::table('companies')->orderBy('id')->pluck('id')->all();
        $primary = $companies[0] ?? null;

        if ($primary) {
            $originals = DB::table('departments')->where('company_id', $primary)
                ->whereNotNull('code')->pluck('id', 'code');

            $copies = DB::table('departments')->where('company_id', '!=', $primary)
                ->whereNotNull('code')->get();

            foreach ($copies as $copy) {
                $originalId = $originals[$copy->code] ?? null;
                if ($originalId) {
                    DB::table('users')->where('department_id', $copy->id)
                        ->update(['department_id' => $originalId]);
                    DB::table('department_user')->where('department_id', $copy->id)->delete();
                    DB::table('departments')->where('id', $copy->id)->delete();
                }
            }
        }

        Schema::table('departments', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'is_active']);
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('code');
        });
    }

    /** Руководитель отдела в копии — только если он работает в этой фирме. */
    private function headForCompany(?int $headUserId, int $companyId): ?int
    {
        if (! $headUserId) {
            return null;
        }

        return DB::table('company_user')->where('user_id', $headUserId)
            ->where('company_id', $companyId)->exists() ? $headUserId : null;
    }
};
