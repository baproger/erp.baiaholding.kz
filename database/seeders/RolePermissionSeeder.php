<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Permissions grouped by module (viewAny/view/create/update/delete).
        $modules = [
            'department', 'client', 'product', 'deal', 'project',
            'task', 'invoice', 'payment', 'expense', 'document',
            'user', 'role', 'setting', 'report',
        ];
        $abilities = ['viewAny', 'view', 'create', 'update', 'delete'];

        foreach ($modules as $module) {
            foreach ($abilities as $ability) {
                Permission::findOrCreate("{$module}.{$ability}", 'web');
            }
        }

        // Roles.
        $admin = Role::findOrCreate('admin', 'web');
        $admin->syncPermissions(Permission::all());

        $manager = Role::findOrCreate('manager', 'web');
        $manager->syncPermissions(
            Permission::whereIn('name', $this->managerAbilities())->get()
        );

        $employee = Role::findOrCreate('employee', 'web');
        $employee->syncPermissions([
            'deal.viewAny', 'deal.view',
            'project.viewAny', 'project.view',
            'task.viewAny', 'task.view', 'task.update',
            'client.viewAny', 'client.view',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function managerAbilities(): array
    {
        $abilities = [];
        foreach (['client', 'deal', 'project', 'task', 'invoice', 'payment', 'expense', 'document', 'product'] as $module) {
            foreach (['viewAny', 'view', 'create', 'update', 'delete'] as $ability) {
                $abilities[] = "{$module}.{$ability}";
            }
        }
        $abilities[] = 'report.viewAny';
        $abilities[] = 'department.viewAny';

        return $abilities;
    }
}
