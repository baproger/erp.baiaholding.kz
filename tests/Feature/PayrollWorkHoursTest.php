<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\WorkHour;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Почасовой оклад (Excel владельца): ставка/час = оклад ÷ норма часов месяца,
 * начислено = отработанные часы × ставка. Часы не введены — полный оклад.
 */
class PayrollWorkHoursTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    private function user(string $role, float $salary = 0): User
    {
        $u = User::factory()->create(['salary' => $salary]);
        $u->assignRole($role);

        return $u;
    }

    // Пример из Excel владельца: оклад 330 000, норма 222 ч, отработано 189 ч
    // → ставка 1486.486486, начислено 280 945.95 (AL5 = AH5 × AI5/AJ5).
    public function test_hours_entered_pay_is_hours_times_hourly_rate(): void
    {
        $admin = $this->user('admin');
        $worker = $this->user('employee', 330000);
        $month = now()->format('Y-m');

        $this->actingAs($admin)->patch(route('payroll.norm'), ['month' => $month, 'norm' => 222])->assertRedirect();
        $this->actingAs($admin)->patch(route('payroll.hours', $worker), ['month' => $month, 'hours' => 189])->assertRedirect();

        $this->actingAs($admin)->get(route('payroll.index', ['month' => $month]))
            ->assertInertia(fn (Assert $p) => $p->component('Payroll/Index')
                ->where('normHours', 222)
                ->where('rows', fn ($rows) => collect($rows)->contains(fn ($r) => $r['uid'] === $worker->id
                    && $r['hours'] == 189.0
                    && $r['hourly_rate'] == 1486.49
                    && $r['base'] == 280945.95
                    && $r['final'] == 280945.95)));
    }

    public function test_no_hours_entered_pays_full_salary(): void
    {
        $admin = $this->user('admin');
        $worker = $this->user('employee', 330000);
        Setting::set('work_norm_'.now()->format('Y-m'), 222);

        $this->actingAs($admin)->get(route('payroll.index'))
            ->assertInertia(fn (Assert $p) => $p->component('Payroll/Index')
                ->where('rows', fn ($rows) => collect($rows)->contains(fn ($r) => $r['uid'] === $worker->id
                    && $r['hours'] === null
                    && $r['base'] == 330000.0
                    && $r['final'] == 330000.0)));
    }

    public function test_clearing_hours_returns_to_full_salary(): void
    {
        $admin = $this->user('admin');
        $worker = $this->user('employee', 330000);
        $month = now()->format('Y-m');
        WorkHour::create(['user_id' => $worker->id, 'month' => $month, 'hours' => 100]);

        $this->actingAs($admin)->patch(route('payroll.hours', $worker), ['month' => $month, 'hours' => null])->assertRedirect();

        $this->assertDatabaseMissing('work_hours', ['user_id' => $worker->id, 'month' => $month]);
    }

    public function test_manager_cannot_set_hours_or_norm(): void
    {
        $mgr = $this->user('manager', 100000);
        $worker = $this->user('employee', 330000);
        $month = now()->format('Y-m');

        $this->actingAs($mgr)->patch(route('payroll.hours', $worker), ['month' => $month, 'hours' => 100])->assertForbidden();
        $this->actingAs($mgr)->patch(route('payroll.norm'), ['month' => $month, 'norm' => 200])->assertForbidden();
    }

    public function test_norm_is_remembered_as_default_for_next_months(): void
    {
        $admin = $this->user('admin');
        $month = now()->format('Y-m');

        $this->actingAs($admin)->patch(route('payroll.norm'), ['month' => $month, 'norm' => 222])->assertRedirect();

        $this->assertEquals(222, Setting::get('work_norm_'.$month));
        $this->assertEquals(222, Setting::get('work_norm_default'));
    }
}
