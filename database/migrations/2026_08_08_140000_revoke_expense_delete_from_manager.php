<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Забирает у менеджера право `expense.delete`: удалять расходы может только
 * бухгалтер/админ (просьба владельца 08.08.2026).
 *
 * Сделано миграцией, а не пересевом RolePermissionSeeder: сидер синхронизирует
 * права всех ролей целиком, а миграция трогает ровно одну связь — и, главное,
 * выполняется сама при деплое (`php artisan migrate --force` уже в хуках
 * Plesk), без ручной команды на сервере.
 *
 * Сам запрет держится и без неё — ExpensePolicy::delete проверяет роль, — но
 * висящее в базе право путало бы админку.
 */
return new class extends Migration
{
    private const PERMISSION = 'expense.delete';

    private const ROLE = 'manager';

    public function up(): void
    {
        $this->toggle(revoke: true);
    }

    public function down(): void
    {
        $this->toggle(revoke: false);
    }

    private function toggle(bool $revoke): void
    {
        $roleId = DB::table('roles')->where('name', self::ROLE)->value('id');
        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        if (! $roleId || ! $permissionId) {
            return; // роли/права ещё нет — сидер выдаст уже правильный набор
        }

        if ($revoke) {
            DB::table('role_has_permissions')
                ->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
        } else {
            DB::table('role_has_permissions')->insertOrIgnore([
                'role_id' => $roleId, 'permission_id' => $permissionId,
            ]);
        }

        // Spatie держит права в кэше — без сброса сервер до конца TTL
        // продолжал бы считать, что право у менеджера есть.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
