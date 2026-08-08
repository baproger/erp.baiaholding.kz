<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Аналитика «По бухгалтерам»: сделки, застрявшие на ИХ этапах (АКТ, ЭСФ).
 * Бухгалтер меряется по СВОИМ задачам — ответственный по сделке остаётся
 * менеджер; просрочка считается по сроку задачи, а не по сроку сделки.
 */
class AccountantAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $financist;

    private User $manager;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $this->company = Company::where('code', 'BAIA')->firstOrFail();
        foreach (['admin' => 'admin', 'financist' => 'financist', 'manager' => 'manager'] as $prop => $role) {
            $u = User::factory()->create();
            $u->assignRole($role);
            $u->companies()->attach($this->company->id);
            $this->{$prop} = $u;
        }
    }

    /**
     * Этапы АКТ/ЭСФ базовый сидер не заводит (их настраивают под фирму в
     * «Настройки → Этапы») — создаём их здесь.
     */
    private function stage(string $stageType): DealStage
    {
        return DealStage::firstOrCreate(
            ['stage_type' => $stageType, 'company_id' => $this->company->id],
            [
                'name' => $stageType === 'act' ? 'Акт утверждение' : 'ЭСФ',
                'type' => 'sale', 'order' => $stageType === 'act' ? 90 : 91,
                'color' => '#10B981', 'is_won' => false, 'is_active' => true, 'checklist' => [],
                'gate_task_title' => 'Подписать акт', 'gate_task_role' => 'financist', 'gate_task_days' => 3,
            ]
        );
    }

    private function dealOn(string $stageType, string $number): Deal
    {
        $stage = $this->stage($stageType);

        return Deal::create([
            'company_id' => $this->company->id, 'number' => $number,
            'name' => 'Сделка', 'company_name' => 'ТОО '.$number, 'budget' => 1_000_000,
            'status' => 'active', 'deal_stage_id' => $stage->id,
            'responsible_user_id' => $this->manager->id,
        ]);
    }

    /** Гейт-задача бухгалтера на сделке с нужным сроком. */
    private function gateTask(Deal $deal, User $assignee, string $dueDate): void
    {
        $deal->tasks()->create([
            'title' => 'Подписать акт по сделке '.$deal->number,
            'status' => 'new', 'priority' => 'high',
            'assignee_id' => $assignee->id, 'creator_id' => $this->manager->id,
            'start_date' => now()->subDays(10), 'due_date' => $dueDate,
        ]);
    }

    private function props(): array
    {
        return $this->actingAs($this->admin)->withSession(['company_id' => $this->company->id])
            ->get(route('analytics.index'))->assertOk()->viewData('page')['props'];
    }

    public function test_overdue_is_measured_by_task_due_date(): void
    {
        $late = $this->dealOn('act', 'BAIA-A-1');
        $onTime = $this->dealOn('esf', 'BAIA-A-2');
        $this->gateTask($late, $this->financist, now()->subDays(5)->toDateString());
        $this->gateTask($onTime, $this->financist, now()->addDays(5)->toDateString());

        $row = collect($this->props()['byAccountant'])->firstWhere('uid', $this->financist->id);

        $this->assertNotNull($row);
        $this->assertSame(2, $row['total']);
        $this->assertSame(1, $row['act']);
        $this->assertSame(1, $row['esf']);
        $this->assertSame(1, $row['overdue'], 'Просрочена ровно одна — по сроку задачи.');
        $this->assertSame(5, $row['max_overdue_days']);
        $this->assertEqualsWithDelta(1_000_000, $row['overdue_budget'], 0.01);
    }

    public function test_deal_keeps_its_manager_while_task_belongs_to_accountant(): void
    {
        $deal = $this->dealOn('act', 'BAIA-A-3');
        $this->gateTask($deal, $this->financist, now()->subDay()->toDateString());

        $row = collect($this->props()['byAccountant'])->firstWhere('uid', $this->financist->id);

        // Строка — бухгалтера, но менеджер сделки в ней остаётся прежний.
        $this->assertSame($this->manager->name, $row['deals'][0]['manager']);
    }

    public function test_done_task_drops_out_of_the_section(): void
    {
        $deal = $this->dealOn('act', 'BAIA-A-4');
        $this->gateTask($deal, $this->financist, now()->subDays(3)->toDateString());
        $deal->tasks()->update(['status' => 'done']);

        $this->assertEmpty($this->props()['byAccountant']);
    }

    public function test_totals_count_each_deal_once_across_accountants(): void
    {
        // Одна сделка — задача КАЖДОМУ бухгалтеру: по строкам она встретится
        // дважды, но в итоге обязана посчитаться один раз.
        $second = User::factory()->create();
        $second->assignRole('financist');
        $second->companies()->attach($this->company->id);

        $deal = $this->dealOn('act', 'BAIA-A-5');
        $this->gateTask($deal, $this->financist, now()->subDays(2)->toDateString());
        $this->gateTask($deal, $second, now()->subDays(2)->toDateString());

        $props = $this->props();

        $this->assertCount(2, $props['byAccountant'], 'У каждого бухгалтера своя строка.');
        $this->assertSame(1, $props['accountantTotals']['overdue_deals']);
        $this->assertEqualsWithDelta(1_000_000, $props['accountantTotals']['overdue_budget'], 0.01);
    }

    public function test_deals_outside_act_and_esf_are_ignored(): void
    {
        $first = DealStage::where('is_active', true)->orderBy('order')->firstOrFail();
        $deal = Deal::create([
            'company_id' => $this->company->id, 'number' => 'BAIA-A-6',
            'name' => 'Сделка', 'company_name' => 'ТОО Ранняя', 'budget' => 500000,
            'status' => 'active', 'deal_stage_id' => $first->id,
            'responsible_user_id' => $this->manager->id,
        ]);
        $this->gateTask($deal, $this->financist, now()->subDays(9)->toDateString());

        $this->assertEmpty($this->props()['byAccountant']);
    }
}
