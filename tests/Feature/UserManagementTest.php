<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('admin');
        return $u;
    }

    public function test_admin_can_create_employee_with_role(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Иван Сотрудник', 'email' => 'ivan@baia.kz',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role' => 'employee', 'is_active' => true,
        ])->assertRedirect();

        $user = User::where('email', 'ivan@baia.kz')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('employee'));
    }

    public function test_index_renders_and_employee_forbidden(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('users.index'))->assertOk();

        $emp = User::factory()->create();
        $emp->assignRole('employee');
        $this->actingAs($emp)->get(route('users.index'))->assertForbidden();
    }
}
