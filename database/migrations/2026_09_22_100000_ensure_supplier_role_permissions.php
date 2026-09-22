<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Правило владельца от 22.09.2026: завсклад (роль supplier — Аманжол) должен
 * видеть Просроченные сделки, Цех и Склад и подтверждать металл на Расходах.
 * Права роли лежат в RolePermissionSeeder, но сидер на проде не запускается —
 * выдаём их миграцией. Идемпотентно: повторный запуск ничего не ломает.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $supplier = Role::findOrCreate('supplier', 'web');
        foreach ([
            'project.viewAny', 'project.view',
            'deal.viewAny', 'deal.view',
            'task.viewAny', 'task.view', 'task.update',
            'expense.viewAny', 'expense.view', 'expense.create',
            'payroll.view',
        ] as $perm) {
            $supplier->givePermissionTo(Permission::findOrCreate($perm, 'web'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Права снимаются вручную в админке — откат не нужен.
    }
};
