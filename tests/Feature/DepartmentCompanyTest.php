<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Отделы принадлежат фирме, а не холдингу: «Отдел продаж» BAIA и ASU —
 * разные отделы. Сотрудник, работающий в обеих фирмах, виден в обеих секциях,
 * но его ЗП считается один раз (в основной фирме).
 */
class DepartmentCompanyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    private function user(string $role, array $companyIds = []): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->companies()->sync($companyIds);

        return $u;
    }

    public function test_department_created_for_both_firms_gets_one_record_per_firm(): void
    {
        $companies = Company::where('is_active', true)->orderBy('id')->pluck('id');
        $admin = $this->user('admin', $companies->all());

        $this->actingAs($admin)
            ->post(route('departments.store'), [
                'name' => 'Отдел продаж',
                'is_active' => true,
                'company_ids' => $companies->all(),
            ])->assertRedirect();

        $created = Department::where('name', 'Отдел продаж')->get();

        $this->assertCount($companies->count(), $created, 'Отдел должен появиться в каждой выбранной фирме.');
        $this->assertEqualsCanonicalizing($companies->all(), $created->pluck('company_id')->all());
        // Общий code связывает копии: по нему сотрудник обеих фирм встаёт
        // в одноимённый отдел в секции каждой из них.
        $this->assertCount(1, $created->pluck('code')->unique(), 'У копий отдела должен быть общий code.');
    }

    public function test_department_defaults_to_current_company_when_not_chosen(): void
    {
        $baia = Company::where('code', 'BAIA')->firstOrFail();
        $admin = $this->user('admin', [$baia->id]);

        $this->actingAs($admin)->withSession(['company_id' => $baia->id])
            ->post(route('departments.store'), ['name' => 'Цех', 'is_active' => true])
            ->assertRedirect();

        $this->assertSame([$baia->id], Department::where('name', 'Цех')->pluck('company_id')->all());
    }

    public function test_department_list_is_scoped_to_current_company(): void
    {
        $baia = Company::where('code', 'BAIA')->firstOrFail();
        $asu = Company::where('code', 'ASU')->firstOrFail();
        Department::create(['company_id' => $baia->id, 'name' => 'Только BAIA', 'code' => 'b', 'is_active' => true]);
        Department::create(['company_id' => $asu->id, 'name' => 'Только ASU', 'code' => 'a', 'is_active' => true]);

        $admin = $this->user('admin', [$baia->id, $asu->id]);

        $names = fn ($page) => collect($page['props']['departments']['data'])->pluck('name');

        $inBaia = $this->actingAs($admin)->withSession(['company_id' => $baia->id])
            ->get(route('departments.index'))->viewData('page');
        $this->assertTrue($names($inBaia)->contains('Только BAIA'));
        $this->assertFalse($names($inBaia)->contains('Только ASU'), 'Отдел чужой фирмы не должен попадать в список.');

        // Режим «Все компании» (company_id = 0) — видны отделы обеих фирм.
        $inAll = $this->actingAs($admin)->withSession(['company_id' => 0])
            ->get(route('departments.index'))->viewData('page');
        $this->assertTrue($names($inAll)->contains('Только BAIA'));
        $this->assertTrue($names($inAll)->contains('Только ASU'));
    }

    public function test_users_page_exposes_company_and_department_code_for_grouping(): void
    {
        $baia = Company::where('code', 'BAIA')->firstOrFail();
        $asu = Company::where('code', 'ASU')->firstOrFail();
        $sales = Department::create(['company_id' => $baia->id, 'name' => 'Отдел продаж', 'code' => 'sales', 'is_active' => true]);
        Department::create(['company_id' => $asu->id, 'name' => 'Отдел продаж', 'code' => 'sales', 'is_active' => true]);

        $admin = $this->user('admin', [$baia->id, $asu->id]);
        $both = $this->user('manager', [$baia->id, $asu->id]);
        $both->update(['department_id' => $sales->id]);

        $page = $this->actingAs($admin)->withSession(['company_id' => 0])
            ->get(route('users.index'))->viewData('page');

        $row = collect($page['props']['users'])->firstWhere('id', $both->id);
        $this->assertSame('sales', $row['department_code']);
        // Сотрудник обеих фирм — обе фирмы в company_ids, страница покажет его в обеих секциях.
        $this->assertEqualsCanonicalizing([$baia->id, $asu->id], collect($row['company_ids'])->all());

        // Отделы приходят с фирмой и кодом — иначе одноимённые не разложить.
        $depts = collect($page['props']['departments']);
        $this->assertEqualsCanonicalizing([$baia->id, $asu->id], $depts->where('code', 'sales')->pluck('company_id')->all());
    }

    public function test_payroll_marks_primary_company_so_dual_employee_is_counted_once(): void
    {
        $baia = Company::where('code', 'BAIA')->firstOrFail();
        $asu = Company::where('code', 'ASU')->firstOrFail();

        $admin = $this->user('admin', [$baia->id, $asu->id]);
        $dual = $this->user('manager', [$baia->id, $asu->id]);
        $dual->update(['salary' => 500000]);

        $page = $this->actingAs($admin)->withSession(['company_id' => 0])
            ->get(route('payroll.index'))->viewData('page');

        $row = collect($page['props']['rows'])->firstWhere('uid', $dual->id);
        $this->assertNotNull($row, 'Сотрудник должен быть в ведомости.');
        $this->assertEqualsCanonicalizing([$baia->id, $asu->id], $row['company_ids']);
        // Основная фирма — первая по id: её итог и получает эту ЗП.
        $this->assertSame(min($baia->id, $asu->id), $row['primary_company_id']);
    }

    public function test_deals_report_summarises_turnover_per_manager(): void
    {
        $baia = Company::where('code', 'BAIA')->firstOrFail();
        $admin = $this->user('admin', [$baia->id]);
        $manager = $this->user('manager', [$baia->id]);
        $stage = DealStage::orderBy('order')->firstOrFail();

        foreach ([1_000_000, 500_000] as $i => $budget) {
            Deal::create([
                'number' => 'BAIA-M-'.$i,
                'name' => 'ТОО Сводка '.$i,
                'company_name' => 'ТОО Сводка '.$i,
                'company_id' => $baia->id,
                'deal_stage_id' => $stage->id,
                'responsible_user_id' => $manager->id,
                'budget' => $budget,
                'status' => 'active',
                'contract_date' => now()->toDateString(),
            ]);
        }

        $page = $this->actingAs($admin)->withSession(['company_id' => $baia->id])
            ->get(route('reports.deals'))->viewData('page');

        $summary = collect($page['props']['byManager'])->firstWhere('manager_id', $manager->id);
        $this->assertNotNull($summary, 'Менеджер должен быть в сводной по МОП.');
        $this->assertSame(2, $summary['deals']);
        // Общий оборот менеджера = сумма договоров его сделок за период.
        $this->assertEqualsWithDelta(1_500_000, $summary['budget'], 0.01);
        // Сводка и таблица считаются из одних данных — итоги обязаны совпасть.
        $this->assertEqualsWithDelta($page['props']['totals']['budget'], $summary['budget'], 0.01);
    }

    public function test_changing_user_companies_moves_department_to_the_twin(): void
    {
        // Отдел принадлежит фирме: перевели сотрудника в другую фирму —
        // он не должен остаться в отделе фирмы, где больше не работает.
        $baia = Company::where('code', 'BAIA')->firstOrFail();
        $asu = Company::where('code', 'ASU')->firstOrFail();
        $inBaia = Department::create(['company_id' => $baia->id, 'name' => 'Отдел продаж', 'code' => 'sales', 'is_active' => true]);
        $inAsu = Department::create(['company_id' => $asu->id, 'name' => 'Отдел продаж', 'code' => 'sales', 'is_active' => true]);

        $admin = $this->user('admin', [$baia->id, $asu->id]);
        $employee = $this->user('manager', [$baia->id]);
        $employee->update(['department_id' => $inBaia->id]);

        $this->actingAs($admin)->put(route('users.update', $employee->id), [
            'name' => $employee->name, 'email' => $employee->email,
            'role' => 'manager', 'department_id' => $inBaia->id,
            'company_ids' => [$asu->id], 'is_active' => true, 'salary' => 0,
        ])->assertRedirect();

        // Переехал в одноимённый отдел ASU, а не остался в BAIA.
        $this->assertSame($inAsu->id, $employee->fresh()->department_id);
    }

    public function test_department_is_cleared_when_new_company_has_no_twin(): void
    {
        $baia = Company::where('code', 'BAIA')->firstOrFail();
        $asu = Company::where('code', 'ASU')->firstOrFail();
        $onlyBaia = Department::create(['company_id' => $baia->id, 'name' => 'Металл цех', 'code' => 'metal', 'is_active' => true]);

        $admin = $this->user('admin', [$baia->id, $asu->id]);
        $employee = $this->user('employee', [$baia->id]);
        $employee->update(['department_id' => $onlyBaia->id]);

        $this->actingAs($admin)->put(route('users.update', $employee->id), [
            'name' => $employee->name, 'email' => $employee->email,
            'role' => 'employee', 'department_id' => $onlyBaia->id,
            'company_ids' => [$asu->id], 'is_active' => true, 'salary' => 0,
        ])->assertRedirect();

        // Одноимённого отдела в ASU нет — отдел снимаем, а не оставляем чужой.
        $this->assertNull($employee->fresh()->department_id);
    }

    /**
     * Изоляция фирм в СПИСКАХ сотрудников: работающий только в ASU не должен
     * появляться в BAIA — ни на странице «Сотрудники», ни в селектах.
     */
    public function test_employee_of_one_company_is_not_listed_in_the_other(): void
    {
        $baia = Company::where('code', 'BAIA')->firstOrFail();
        $asu = Company::where('code', 'ASU')->firstOrFail();

        $admin = $this->user('admin', [$baia->id, $asu->id]);
        $asuOnly = $this->user('manager', [$asu->id]);
        $baiaOnly = $this->user('manager', [$baia->id]);

        $names = fn ($companyId) => collect($this->actingAs($admin)->withSession(['company_id' => $companyId])
            ->get(route('users.index'))->viewData('page')['props']['users'])->pluck('name');

        $inBaia = $names($baia->id);
        $this->assertTrue($inBaia->contains($baiaOnly->name));
        $this->assertFalse($inBaia->contains($asuOnly->name), 'Сотрудник ASU не должен быть в списке BAIA.');

        $inAsu = $names($asu->id);
        $this->assertTrue($inAsu->contains($asuOnly->name));
        $this->assertFalse($inAsu->contains($baiaOnly->name));

        // Режим «Все компании» — видно обоих.
        $inAll = $names(0);
        $this->assertTrue($inAll->contains($asuOnly->name));
        $this->assertTrue($inAll->contains($baiaOnly->name));
    }

    public function test_deal_pickers_are_scoped_to_the_current_company(): void
    {
        $baia = Company::where('code', 'BAIA')->firstOrFail();
        $asu = Company::where('code', 'ASU')->firstOrFail();

        $admin = $this->user('admin', [$baia->id, $asu->id]);
        $asuOnly = $this->user('manager', [$asu->id]);

        $picker = collect($this->actingAs($admin)->withSession(['company_id' => $baia->id])
            ->get(route('deals.index'))->viewData('page')['props']['users'])->pluck('name');

        $this->assertFalse($picker->contains($asuOnly->name),
            'В выборе ответственного по сделке BAIA не должно быть сотрудников ASU.');
    }
}
