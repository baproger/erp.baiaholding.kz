<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\EmployeeStatusService;
use App\Services\PayrollService;
use App\Support\Dict;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Статус «Работает / Уволен» вместо удаления (правило владельца от 07.10.2026):
 * уволенный не входит и не получает новых дел, но его сделки, ЗП, бонусы и
 * аудит остаются — прибыль прошлых периодов не меняется задним числом.
 */
class EmployeeStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    private function user(string $role, array $attrs = []): User
    {
        $u = User::factory()->create($attrs);
        $u->assignRole($role);

        return $u;
    }

    private function wonDeal(User $mgr): Deal
    {
        $won = DealStage::where('is_won', true)->first()->id;
        $deal = Deal::create(['number' => 'D-'.uniqid(), 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И',
            'budget' => 1000000, 'status' => 'active', 'deal_stage_id' => $won, 'responsible_user_id' => $mgr->id]);
        $inv = Invoice::create(['number' => 'I-'.uniqid(), 'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id, 'amount' => 1000000, 'status' => 'paid']);
        Payment::create(['invoice_id' => $inv->id, 'amount' => 1000000, 'payment_date' => now()->toDateString()]);
        Expense::create(['expenseable_type' => 'deal', 'expenseable_id' => $deal->id, 'amount' => 100000, 'date' => now()->toDateString(), 'status' => 'confirmed']);

        return $deal;
    }

    private function openDeal(User $mgr): Deal
    {
        return Deal::create(['number' => 'D-'.uniqid(), 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И',
            'budget' => 100, 'status' => 'active', 'deal_stage_id' => DealStage::orderBy('order')->first()->id, 'responsible_user_id' => $mgr->id]);
    }

    private function fire(User $actor, User $target, array $data = [])
    {
        return $this->actingAs($actor)->delete(route('users.destroy', $target), $data);
    }

    // 1. Увольнение
    public function test_firing_keeps_record_blocks_login_and_ends_session(): void
    {
        $admin = $this->user('admin');
        $mgr = $this->user('manager');

        $this->fire($admin, $mgr, ['fired_at' => '2026-10-05', 'fired_note' => 'по собственному'])->assertSessionHas('success');

        $fresh = User::find($mgr->id);
        $this->assertNotNull($fresh, 'запись не удалена');
        $this->assertNull($fresh->deleted_at);
        $this->assertSame('fired', $fresh->status);
        $this->assertSame('2026-10-05', $fresh->fired_at->toDateString());
        $this->assertSame('по собственному', $fresh->fired_note);
        $this->assertFalse($fresh->is_active);

        // Вход закрыт.
        $this->post(route('logout'));
        $this->post('/login', ['email' => $mgr->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        // Даже если is_active вернули вручную — статус всё равно не пускает.
        User::whereKey($mgr->id)->update(['is_active' => true]);
        $this->post('/login', ['email' => $mgr->email, 'password' => 'password'])->assertSessionHasErrors('email');

        // Активная сессия уволенного завершается на следующем запросе.
        $this->actingAs(User::find($mgr->id))->get('/deals')->assertRedirect('/login');
    }

    // 2. История
    public function test_history_keeps_responsible_and_name(): void
    {
        $admin = $this->user('admin');
        $mgr = $this->user('manager', ['name' => 'Ерлан Уволенный']);
        $deal = $this->wonDeal($mgr);

        $this->fire($admin, $mgr);

        $this->assertNotNull($deal->fresh()->responsible, 'у сделки остаётся ответственный');
        $this->assertSame('Ерлан Уволенный', Dict::users()[$mgr->id] ?? null);
    }

    // 3. ЗП и прибыль прошлых периодов
    public function test_payroll_history_and_company_profit_unchanged_after_firing(): void
    {
        $admin = $this->user('admin');
        $mgr = $this->user('manager', ['salary' => 300000]);
        $this->wonDeal($mgr);

        $payroll = fn () => new PayrollService;
        $bonusBefore = $payroll()->perUser()->firstWhere('uid', $mgr->id)['bonus'];
        $totalsBefore = $payroll()->companyTotals();
        $this->assertGreaterThan(0, $bonusBefore);

        $this->fire($admin, $mgr, ['fired_at' => now()->toDateString()]);

        $row = $payroll()->perUser()->firstWhere('uid', $mgr->id);
        $this->assertNotNull($row, 'уволенный остаётся в ЗП-истории');
        $this->assertSame($bonusBefore, $row['bonus']);
        $this->assertSame(0.0, $row['salary'], 'оклада у уволенного сейчас нет');
        $this->assertEquals($totalsBefore, $payroll()->companyTotals(), 'прибыль компании не меняется задним числом');

        // Уволенный виден в ведомости всегда (группа «Уволенные»): в месяце
        // увольнения — с окладом, в следующем — с нулевым окладом.
        $thisMonth = now()->format('Y-m');
        $nextMonth = now()->addMonthNoOverflow()->format('Y-m');
        $this->actingAs($admin)->get(route('payroll.index', ['month' => $thisMonth]))
            ->assertInertia(fn (Assert $p) => $p->where('rows', function ($rows) use ($mgr) {
                $r = collect($rows)->firstWhere('uid', $mgr->id);

                return $r !== null && $r['status'] === 'fired' && $r['base'] > 0;
            }));
        $this->actingAs($admin)->get(route('payroll.index', ['month' => $nextMonth]))
            ->assertInertia(fn (Assert $p) => $p->where('rows', function ($rows) use ($mgr) {
                $r = collect($rows)->firstWhere('uid', $mgr->id);

                return $r !== null && $r['status'] === 'fired' && (float) $r['base'] === 0.0 && (float) $r['salary_final'] === 0.0;
            }));
    }

    public function test_salary_in_firing_month_is_prorated_by_days(): void
    {
        $admin = $this->user('admin');
        $worker = $this->user('employee', ['salary' => 310000]);
        // Уволен 10 числа месяца из 31 дня → 10/31 оклада.
        $this->fire($admin, $worker, ['fired_at' => '2026-10-10']);

        $this->actingAs($admin)->get(route('payroll.index', ['month' => '2026-10']))
            ->assertInertia(fn (Assert $p) => $p->where('rows', function ($rows) use ($worker) {
                $r = collect($rows)->firstWhere('uid', $worker->id);

                return $r !== null && abs($r['base'] - 100000.0) < 0.01;
            }));
        // Месяц до увольнения — полный оклад.
        $this->actingAs($admin)->get(route('payroll.index', ['month' => '2026-09']))
            ->assertInertia(fn (Assert $p) => $p->where('rows', function ($rows) use ($worker) {
                $r = collect($rows)->firstWhere('uid', $worker->id);

                return $r !== null && abs($r['base'] - 310000.0) < 0.01;
            }));
    }

    // Годовой свод ЗП: сумма помесячных ведомостей, будущие месяцы не входят, только просмотр.
    public function test_year_view_sums_months_and_is_read_only(): void
    {
        Carbon::setTestNow('2026-03-15 10:00:00');
        $admin = $this->user('admin');
        $worker = $this->user('employee', ['salary' => 100000]);
        // Январь–март (текущий месяц март) → 3 оклада; премия в феврале.
        \App\Models\PayrollAdjustment::create(['user_id' => $worker->id, 'type' => 'bonus', 'amount' => 5000,
            'date' => '2026-02-10', 'created_by' => $admin->id]);

        $this->actingAs($admin)->get(route('payroll.index', ['year' => 2026]))
            ->assertInertia(fn (Assert $p) => $p->component('Payroll/Index')
                ->where('year', 2026)
                ->where('canManage', false)
                ->where('rows', function ($rows) use ($worker) {
                    $r = collect($rows)->firstWhere('uid', $worker->id);

                    return $r !== null && abs($r['base'] - 300000.0) < 0.01 && abs($r['additions'] - 5000.0) < 0.01
                        && count($r['adjustments']) === 1;
                }));
        Carbon::setTestNow();
    }

    public function test_hire_month_prorated_and_months_before_hire_excluded(): void
    {
        $admin = $this->user('admin');
        // Принят 22-го в месяце из 31 дня → 10/31 оклада; месяц раньше — нет в ведомости.
        $worker = $this->user('employee', ['salary' => 310000, 'hired_at' => '2026-10-22']);

        $this->actingAs($admin)->get(route('payroll.index', ['month' => '2026-10']))
            ->assertInertia(fn (Assert $p) => $p->where('rows', function ($rows) use ($worker) {
                $r = collect($rows)->firstWhere('uid', $worker->id);

                return $r !== null && abs($r['base'] - 100000.0) < 0.01;
            }));
        $this->actingAs($admin)->get(route('payroll.index', ['month' => '2026-09']))
            ->assertInertia(fn (Assert $p) => $p->where('rows', fn ($rows) => ! collect($rows)->contains('uid', $worker->id)));
    }

    // 4. Передача дел
    public function test_handover_moves_only_open_items_to_successor(): void
    {
        $admin = $this->user('admin');
        $mgr = $this->user('manager');
        $next = $this->user('manager');

        $open = $this->openDeal($mgr);
        $won = $this->wonDeal($mgr);
        $project = Project::create(['number' => 'P-1', 'name' => 'Заказ', 'deal_id' => $open->id, 'status' => 'active', 'responsible_user_id' => $mgr->id]);
        $doneProject = Project::create(['number' => 'P-2', 'name' => 'Готов', 'deal_id' => $won->id, 'status' => 'completed', 'responsible_user_id' => $mgr->id]);
        $task = Task::create(['title' => 'Позвонить', 'status' => 'todo', 'assignee_id' => $mgr->id, 'creator_id' => $admin->id]);
        $doneTask = Task::create(['title' => 'Сделано', 'status' => 'done', 'assignee_id' => $mgr->id, 'creator_id' => $admin->id]);

        $this->fire($admin, $mgr, ['successor_user_id' => $next->id])->assertSessionHas('success');

        $this->assertSame($next->id, $open->fresh()->responsible_user_id);
        $this->assertSame($mgr->id, $won->fresh()->responsible_user_id, 'закрытая (won) сделка остаётся за уволенным');
        $this->assertSame($next->id, $project->fresh()->responsible_user_id);
        $this->assertSame($mgr->id, $doneProject->fresh()->responsible_user_id);
        $this->assertSame($next->id, $task->fresh()->assignee_id);
        $this->assertSame($mgr->id, $doneTask->fresh()->assignee_id);
    }

    public function test_without_successor_nothing_is_reassigned(): void
    {
        $admin = $this->user('admin');
        $mgr = $this->user('manager');
        $open = $this->openDeal($mgr);

        $this->fire($admin, $mgr)->assertSessionHas('success');

        $this->assertSame($mgr->id, $open->fresh()->responsible_user_id);
    }

    public function test_successor_must_be_working_and_not_self(): void
    {
        $admin = $this->user('admin');
        $mgr = $this->user('manager');
        $gone = $this->user('manager', ['status' => 'fired', 'is_active' => false]);

        $this->fire($admin, $mgr, ['successor_user_id' => $gone->id])->assertSessionHasErrors('successor_user_id');
        $this->fire($admin, $mgr, ['successor_user_id' => $mgr->id])->assertSessionHasErrors('successor_user_id');
        $this->assertSame('working', $mgr->fresh()->status ?? 'working');
    }

    // 5. Восстановление
    public function test_restore_by_admin_only_and_login_works_again(): void
    {
        $admin = $this->user('admin');
        $director = $this->user('director');
        $mgr = $this->user('manager');
        $this->fire($admin, $mgr);

        $this->actingAs($director)->patch(route('users.restore', $mgr))->assertForbidden();
        $this->actingAs($admin)->patch(route('users.restore', $mgr))->assertSessionHas('success');

        $fresh = $mgr->fresh();
        $this->assertSame('working', $fresh->status);
        $this->assertTrue($fresh->is_active);
        $this->assertNull($fresh->fired_at);

        $this->post(route('logout'));
        $this->post('/login', ['email' => $mgr->email, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    // 6. Новые дела уволенному не назначаются
    public function test_fired_user_is_not_offered_as_responsible(): void
    {
        $admin = $this->user('admin');
        $mgr = $this->user('manager');
        $this->fire($admin, $mgr);

        $this->actingAs($admin)->get(route('deals.index'))
            ->assertInertia(fn (Assert $p) => $p->where('users', fn ($users) => ! collect($users)->contains('id', $mgr->id)));
        $this->assertFalse(User::working()->whereKey($mgr->id)->exists());
    }

    // 7. Миграция: ранее удалённые → уволенные
    public function test_soft_deleted_users_are_restored_as_fired(): void
    {
        $u = $this->user('manager');
        Carbon::setTestNow('2026-08-24 12:00:00');
        $u->delete();
        Carbon::setTestNow();
        $this->assertNull(User::find($u->id));

        $this->assertSame(1, EmployeeStatusService::restoreSoftDeleted());

        $fresh = User::find($u->id);
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->deleted_at);
        $this->assertSame('fired', $fresh->status);
        $this->assertSame('2026-08-24', $fresh->fired_at->toDateString());
        $this->assertFalse($fresh->is_active);
    }

    // Безопасность: удалённый ранее админ, возвращённый миграцией как уволенный, войти не может.
    public function test_restored_former_admin_cannot_log_in(): void
    {
        $exAdmin = $this->user('admin');
        $exAdmin->delete();
        EmployeeStatusService::restoreSoftDeleted();

        $this->post('/login', ['email' => $exAdmin->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        // И уже открытая сессия такого аккаунта завершается.
        $this->actingAs(User::find($exAdmin->id))->get('/deals')->assertRedirect('/login');
    }

    // 8. Супер-админ
    public function test_super_admin_cannot_be_fired(): void
    {
        $admin = $this->user('admin');
        $other = $this->user('admin');

        $this->fire($admin, $other)->assertForbidden();
        $this->assertSame('working', $other->fresh()->status ?? 'working');
    }

    public function test_users_page_exposes_status_and_open_counts(): void
    {
        $admin = $this->user('admin');
        $mgr = $this->user('manager');
        $this->openDeal($mgr);
        Task::create(['title' => 'X', 'status' => 'todo', 'assignee_id' => $mgr->id, 'creator_id' => $admin->id]);

        $this->actingAs($admin)->get(route('users.index'))
            ->assertInertia(fn (Assert $p) => $p->where('users', function ($users) use ($mgr) {
                $row = collect($users)->firstWhere('id', $mgr->id);

                return $row['status'] === 'working' && $row['open_deals'] === 1 && $row['open_tasks'] === 1;
            })->where('can.fire', true)->where('can.restore', true));
    }
}
