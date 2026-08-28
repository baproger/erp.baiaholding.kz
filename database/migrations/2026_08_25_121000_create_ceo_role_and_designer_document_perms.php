<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Правило от 25.08.2026:
 *  - роль CEO: доступ как у admin, но супер-админа не трогает (см. User::hasRole);
 *  - дизайнер прикрепляет смету к сделке → права на документы.
 * Сидер на проде не запускается — делаем миграцией (идемпотентно).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate('ceo', 'web')->syncPermissions(Permission::all());

        $designer = Role::findOrCreate('designer', 'web');
        foreach (['document.viewAny', 'document.view', 'document.create'] as $perm) {
            $designer->givePermissionTo(Permission::findOrCreate($perm, 'web'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Роль не удаляем: у сотрудников могут быть назначения.
    }
};
