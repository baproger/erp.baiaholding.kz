<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Заявка «Расход компании» доступна ЛЮБОМУ сотруднику: он выставляет счёт
 * бухгалтеру на оплату, бухгалтер проверяет чек и оплачивает.
 *
 * Выдаём только create/view — подтверждение и удаление остаются у бухгалтера
 * (ExpensePolicy проверяет роль, а не только право). Миграцией, а не пересевом
 * ролей: пересев затёр бы права, настроенные на боевом.
 */
return new class extends Migration
{
    private const ROLES = ['employee', 'lawyer', 'cook', 'designer', 'supplier'];

    private const PERMISSIONS = ['expense.create', 'expense.viewAny', 'expense.view'];

    public function up(): void
    {
        $this->toggle(grant: true);
    }

    public function down(): void
    {
        $this->toggle(grant: false);
    }

    private function toggle(bool $grant): void
    {
        $roleIds = DB::table('roles')->whereIn('name', self::ROLES)->pluck('id');
        $permIds = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id');

        if ($roleIds->isEmpty() || $permIds->isEmpty()) {
            return; // чистая база — сидер выдаст уже правильный набор
        }

        foreach ($roleIds as $roleId) {
            foreach ($permIds as $permId) {
                $grant
                    ? DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permId])
                    : DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $permId)->delete();
            }
        }

        // Spatie держит права в кэше — без сброса сервер до конца TTL считал бы,
        // что права не изменились.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
