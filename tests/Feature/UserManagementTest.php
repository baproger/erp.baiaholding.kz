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

    public function test_financist_can_add_employee_but_not_admin(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $fin = \App\Models\User::factory()->create();
        $fin->assignRole('financist');

        $payload = fn (string $role, string $email) => [
            'name' => 'Новый сотрудник', 'email' => $email,
            'password' => 'secret123', 'password_confirmation' => 'secret123', 'role' => $role,
        ];

        // Менеджера — можно.
        $this->actingAs($fin)->post(route('users.store'), $payload('manager', 'new1@baia.kz'))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue(\App\Models\User::where('email', 'new1@baia.kz')->first()->hasRole('manager'));

        // Админа — нельзя (только действующий админ).
        $this->actingAs($fin)->post(route('users.store'), $payload('admin', 'new2@baia.kz'))
            ->assertForbidden();
    }

    public function test_financist_can_edit_and_deactivate_employee_but_not_admin(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $fin = \App\Models\User::factory()->create();
        $fin->assignRole('financist');
        $emp = \App\Models\User::factory()->create(['name' => 'Иван']);
        $emp->assignRole('manager');
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        // Правка и деактивация обычного сотрудника — можно.
        $this->actingAs($fin)->put(route('users.update', $emp->id), [
            'name' => 'Иван Обновлённый', 'email' => $emp->email, 'role' => 'manager',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Иван Обновлённый', $emp->fresh()->name);

        // Админа — нельзя: ни править (поля не должны измениться), ни деактивировать.
        $adminName = $admin->name;
        $this->actingAs($fin)->put(route('users.update', $admin->id), [
            'name' => 'Взлом', 'email' => $admin->email, 'role' => 'admin',
        ])->assertForbidden();
        $this->assertSame($adminName, $admin->fresh()->name);
        $this->actingAs($fin)->delete(route('users.destroy', $admin->id))->assertForbidden();

        $this->actingAs($fin)->delete(route('users.destroy', $emp->id))->assertRedirect();
        $this->assertFalse($emp->fresh()->is_active);
    }

    /** Добавление сотрудника целиком: роль, фирмы, отдел, статус «работает», вход (13.10.2026). */
    public function test_admin_adds_employee_who_can_log_in(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');
        $asu = \App\Models\Company::where('code', 'ASU')->firstOrFail();

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Новый Менеджер', 'email' => 'new.manager@baia.kz', 'role' => 'manager',
            'password' => 'Qw9!zx7Lm', 'password_confirmation' => 'Qw9!zx7Lm',
            'company_ids' => [$asu->id], 'salary' => 250000, 'hired_at' => '2026-10-13',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $u = \App\Models\User::where('email', 'new.manager@baia.kz')->firstOrFail();
        $this->assertTrue($u->hasRole('manager'));
        $this->assertSame('working', $u->fresh()->status);
        $this->assertTrue($u->is_active);
        $this->assertSame([$asu->id], $u->companies()->pluck('companies.id')->all());

        $this->post(route('logout'));
        $this->post('/login', ['email' => 'new.manager@baia.kz', 'password' => 'Qw9!zx7Lm'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($u);
    }

    /** Нет права выдать роль admin — сотрудник НЕ создаётся (раньше оставался без роли). */
    public function test_forbidden_role_creates_nothing(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $director = \App\Models\User::factory()->create();
        $director->assignRole('director');

        $this->actingAs($director)->post(route('users.store'), [
            'name' => 'X', 'email' => 'sneaky@baia.kz', 'role' => 'admin',
            'password' => 'Qw9!zx7Lm', 'password_confirmation' => 'Qw9!zx7Lm',
        ])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'sneaky@baia.kz']);
    }

    /** Ошибки формы — по-русски (дубль email с подсказкой про «Уволенные»). */
    public function test_validation_messages_are_russian(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = \App\Models\User::factory()->create(['email' => 'taken@baia.kz']);
        $admin->assignRole('admin');

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Дубль', 'email' => 'taken@baia.kz', 'role' => 'manager',
            'password' => 'short', 'password_confirmation' => 'other',
        ])->assertSessionHasErrors([
            'email' => 'Сотрудник с таким email уже есть. Если он уволен — найдите его на вкладке «Уволенные» и нажмите «Восстановить».',
        ]);
        $this->assertStringContainsString('Пароли не совпадают', implode(' ', session('errors')->get('password')));
    }
}
