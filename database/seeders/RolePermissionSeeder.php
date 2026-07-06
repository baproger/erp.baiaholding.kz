<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
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

        Permission::findOrCreate('payroll.view', 'web');

        $admin = Role::findOrCreate('admin', 'web');
        $admin->syncPermissions(Permission::all());

        $director = Role::findOrCreate('director', 'web');
        $director->syncPermissions(Permission::all());

        $financist = Role::findOrCreate('financist', 'web');
        $financist->syncPermissions(Permission::whereIn('name', [
            'invoice.viewAny', 'invoice.view', 'invoice.create', 'invoice.update', 'invoice.delete',
            'payment.viewAny', 'payment.view', 'payment.create', 'payment.update', 'payment.delete',
            'expense.viewAny', 'expense.view', 'expense.create', 'expense.update', 'expense.delete',
            'deal.viewAny', 'deal.view',
            'project.viewAny', 'project.view',
            'client.viewAny', 'client.view',
            'payroll.view',
        ])->get());

        $manager = Role::findOrCreate('manager', 'web');
        $manager->syncPermissions(Permission::whereIn('name', $this->managerAbilities())->get());

        $employee = Role::findOrCreate('employee', 'web');
        $employee->syncPermissions([
            'project.viewAny', 'project.view',
            'task.viewAny', 'task.view', 'task.update',
            'payroll.view',
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
        $abilities[] = 'department.viewAny';
        $abilities[] = 'payroll.view';

        return $abilities;
    }
}
