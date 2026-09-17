<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ErrorLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ошибки браузера (JS) попадают в тот же журнал Аудит → Ошибки —
 * правило владельца: админ видит ВСЕ ошибки сайта (17.09.2026).
 */
class ClientErrorLogTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $this->seed(RolePermissionSeeder::class);
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->companies()->attach(Company::where('code', 'BAIA')->firstOrFail()->id);

        return $u;
    }

    public function test_browser_error_lands_in_journal_and_is_visible_to_admin(): void
    {
        $mgr = $this->user('manager');

        $this->actingAs($mgr)->postJson(route('clientErrors.store'), [
            'name' => 'TypeError',
            'message' => "Cannot read properties of undefined (reading 'deal_id')",
            'source' => 'PlanFact.js:1',
            'url' => 'https://erp.baiaholding.kz/reports/plan-fact',
            'stack' => 'at PlanFact.js:1:10766',
        ])->assertOk();

        $log = ErrorLog::latest('id')->firstOrFail();
        $this->assertSame('JS: TypeError', $log->exception);
        $this->assertSame('BROWSER', $log->method);
        $this->assertSame($mgr->id, $log->user_id, 'видно, у кого сломалось');
        $this->assertStringContainsString('deal_id', $log->message);

        // Админ видит её на странице Аудит → Ошибки.
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get(route('audit.errors'))
            ->assertOk()->assertSee('JS: TypeError');
    }

    public function test_guest_cannot_write_to_journal(): void
    {
        $this->seed(RolePermissionSeeder::class);
        // Без входа — не пишем (редирект на логин или 401, как настроен guard).
        $this->assertContains(
            $this->post(route('clientErrors.store'), ['message' => 'спам'])->getStatusCode(),
            [302, 401]
        );
        $this->assertSame(0, ErrorLog::count());
    }
}
