<?php

namespace Tests\Feature;

use App\Models\LoginLog;
use App\Models\Setting;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\LoginSecurity;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Безопасность входа (23.09.2026): отключённые, код входа, устройства, журнал, пароли. */
class LoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(string $role = 'manager', array $attrs = []): User
    {
        $u = User::factory()->create($attrs);
        $u->assignRole($role);

        return $u;
    }

    private function login(User $u, string $password = 'password')
    {
        return $this->post('/login', ['email' => $u->email, 'password' => $password]);
    }

    // ---- 1. Отключённый сотрудник ----

    public function test_disabled_user_cannot_login_and_it_is_logged(): void
    {
        $u = $this->user('manager', ['is_active' => false]);

        $this->login($u)->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('login_logs', ['user_id' => $u->id, 'result' => 'disabled']);
    }

    public function test_user_disabled_mid_session_is_logged_out_on_next_request(): void
    {
        $u = $this->user();
        $this->actingAs($u)->get('/deals')->assertOk();

        User::whereKey($u->id)->update(['is_active' => false]);

        $this->actingAs($u->fresh())->get('/deals')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_deactivating_user_via_admin_revokes_sessions_and_devices(): void
    {
        $admin = $this->user('admin');
        $u = $this->user();
        TrustedDevice::create(['user_id' => $u->id, 'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDays(10)]);

        $this->actingAs($admin)->delete(route('users.destroy', $u))->assertRedirect();

        $this->assertDatabaseCount('trusted_devices', 0);
        $this->assertNotNull($u->fresh()->security_stamp);
        $this->assertDatabaseHas('login_logs', ['user_id' => $u->id, 'result' => 'session_revoked']);
    }

    public function test_super_admin_is_never_locked_out_by_is_active_flag(): void
    {
        $admin = $this->user('admin', ['is_active' => false]);

        $this->login($admin)->assertRedirect(route('dashboard', absolute: false));
        $this->get('/deals')->assertOk();
    }

    // ---- 4. Журнал входов + выход везде при смене пароля ----

    public function test_successful_and_failed_logins_are_logged(): void
    {
        $u = $this->user();

        $this->login($u, 'wrong');
        $this->assertDatabaseHas('login_logs', ['email' => $u->email, 'result' => 'failed_password']);

        $this->login($u)->assertRedirect(route('dashboard', absolute: false));
        $this->assertDatabaseHas('login_logs', ['user_id' => $u->id, 'result' => 'success']);
    }

    public function test_password_change_logs_out_other_sessions_but_keeps_current(): void
    {
        $admin = $this->user('admin');
        $this->login($admin)->assertRedirect();

        $this->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'NewStrongPass9',
            'password_confirmation' => 'NewStrongPass9',
        ])->assertSessionHasNoErrors();

        // Текущая сессия жива (отметка обновлена вместе с пользователем).
        $this->get('/deals')->assertOk();

        // «Другая» сессия со старой (пустой) отметкой — завершается.
        $this->flushSession();
        $this->actingAs($admin->fresh())->get('/deals')->assertRedirect('/login');
    }

    public function test_login_journal_is_admin_only(): void
    {
        $this->actingAs($this->user('admin'))->get(route('audit.logins'))->assertOk();
        $this->actingAs($this->user('director'))->get(route('audit.logins'))->assertForbidden();
    }

    // ---- 3. Пароли ----

    public function test_short_password_is_rejected_when_creating_employee(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Тест', 'email' => 'short@baia.kz', 'role' => 'manager',
            'password' => 'abc123', 'password_confirmation' => 'abc123',
        ])->assertSessionHasErrors('password');

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Тест', 'email' => 'ok@baia.kz', 'role' => 'manager',
            'password' => 'abcd1234', 'password_confirmation' => 'abcd1234',
        ])->assertSessionHasNoErrors();
    }

    // ---- 5. Код входа ----

    public function test_code_not_required_when_setting_is_off(): void
    {
        $u = $this->user();
        $this->login($u)->assertRedirect(route('dashboard', absolute: false));
        $this->get('/deals')->assertOk();
    }

    public function test_login_redirects_to_code_screen_and_blocks_pages_until_verified(): void
    {
        Setting::set('require_login_code', true);
        $u = $this->user();

        $this->login($u)->assertRedirect(route('login.code'));
        $this->get('/deals')->assertRedirect(route('login.code'));
        $this->get(route('login.code'))->assertOk();
    }

    public function test_wrong_code_fails_and_is_logged_and_locks_after_five_attempts(): void
    {
        Setting::set('require_login_code', true);
        $u = $this->user();
        LoginSecurity::issueCode($u, null);
        $this->login($u);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.code.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        }
        $this->assertSame(5, LoginLog::where('user_id', $u->id)->where('result', 'failed_code')->count());

        $this->post(route('login.code.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertDatabaseHas('login_logs', ['user_id' => $u->id, 'result' => 'code_locked']);
        $this->get('/deals')->assertRedirect(route('login.code'));
    }

    public function test_correct_code_opens_system_and_trusts_device(): void
    {
        Setting::set('require_login_code', true);
        $u = $this->user();
        $code = LoginSecurity::issueCode($u, null);
        $this->login($u);

        $resp = $this->post(route('login.code.verify'), ['code' => $code]);
        $resp->assertRedirect(route('dashboard', absolute: false));
        $resp->assertCookie(LoginSecurity::COOKIE);

        $this->get('/deals')->assertOk();
        $this->assertDatabaseCount('trusted_devices', 1);
        $this->assertNull($u->fresh()->login_code_hash, 'код одноразовый');
        $this->assertDatabaseHas('login_logs', ['user_id' => $u->id, 'result' => 'code_ok']);

        // Повторный вход с этого устройства — без кода.
        $cookie = $resp->getCookie(LoginSecurity::COOKIE, false)->getValue();
        $this->post(route('logout'));
        $this->withUnencryptedCookie(LoginSecurity::COOKIE, $cookie)
            ->login($u)->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_expired_code_is_rejected(): void
    {
        Setting::set('require_login_code', true);
        $u = $this->user();
        $code = LoginSecurity::issueCode($u, null);
        User::whereKey($u->id)->update(['login_code_expires_at' => now()->subMinute()]);
        $this->login($u);

        $this->post(route('login.code.verify'), ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_admin_issues_code_and_non_admin_cannot(): void
    {
        $admin = $this->user('admin');
        $u = $this->user();

        $resp = $this->actingAs($admin)->post(route('users.login-code', $u));
        $resp->assertRedirect()->assertSessionHas('login_code');
        $code = session('login_code')['code'];
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertTrue(LoginSecurity::verifyCode($u->fresh(), $code));
        $this->assertDatabaseHas('login_logs', ['user_id' => $u->id, 'result' => 'code_issued']);

        $this->actingAs($this->user('director'))->post(route('users.login-code', $u))->assertForbidden();
    }

    public function test_revoking_devices_requires_code_again(): void
    {
        $admin = $this->user('admin');
        $u = $this->user();
        TrustedDevice::create(['user_id' => $u->id, 'token_hash' => str_repeat('b', 64), 'expires_at' => now()->addDays(10)]);

        $this->actingAs($admin)->delete(route('users.devices.revoke', $u))->assertRedirect();
        Setting::set('require_login_code', true);

        $this->assertDatabaseCount('trusted_devices', 0);
        $this->assertDatabaseHas('login_logs', ['user_id' => $u->id, 'result' => 'devices_revoked']);
        $this->flushSession();
        $this->login($u)->assertRedirect(route('login.code'));
    }

    public function test_security_fields_never_reach_the_audit_log_or_frontend(): void
    {
        $admin = $this->user('admin');
        $u = $this->user();
        LoginSecurity::issueCode($u, $admin);

        $this->assertDatabaseMissing('audit_logs', ['field' => 'login_code_hash']);
        $this->assertDatabaseMissing('audit_logs', ['field' => 'security_stamp']);
        $this->assertArrayNotHasKey('login_code_hash', $u->fresh()->toArray());
        $this->assertArrayNotHasKey('security_stamp', $u->fresh()->toArray());
    }
}
