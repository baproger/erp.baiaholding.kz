<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealsReportTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    public function test_report_renders_for_admin_director_and_financist(): void
    {
        // Финансист видит полный отчёт (маржа, минусовые проекты) — 17.08.2026.
        $this->actingAs($this->user('admin'))->get(route('reports.deals'))->assertOk();
        $this->actingAs($this->user('director'))->get(route('reports.deals'))->assertOk();
        $this->actingAs($this->user('financist'))->get(route('reports.deals'))->assertOk();
    }

    public function test_report_forbidden_for_employee(): void
    {
        // Полный отчёт показывает бонусы ВСЕХ менеджеров — рядовому сотруднику
        // нельзя (у МОПа свой урезанный срез, см. тесты ниже).
        $this->actingAs($this->user('employee'))->get(route('reports.deals'))->assertForbidden();
    }

    public function test_manager_sees_only_own_deals_and_cannot_spy_on_others(): void
    {
        $mine = $this->user('manager');
        $other = $this->user('manager');
        $stage = DealStage::orderBy('order')->first();

        Deal::create(['number' => 'D-MINE', 'name' => 'Моя', 'company_name' => 'Моя',
            'budget' => 1000, 'status' => 'active', 'deal_stage_id' => $stage->id,
            'responsible_user_id' => $mine->id]);
        Deal::create(['number' => 'D-OTHER', 'name' => 'Чужая', 'company_name' => 'Чужая',
            'budget' => 9000, 'status' => 'active', 'deal_stage_id' => $stage->id,
            'responsible_user_id' => $other->id]);

        // Даже с чужим ?manager= в адресе МОП остаётся прибит к своим сделкам.
        $page = $this->actingAs($mine)
            ->get(route('reports.deals', ['manager' => $other->id]))
            ->assertOk()->viewData('page');

        $numbers = collect($page['props']['rows'])->pluck('number');
        $this->assertTrue($numbers->contains('D-MINE'));
        $this->assertFalse($numbers->contains('D-OTHER'), 'МОП не должен видеть чужие сделки.');
        $this->assertFalse($page['props']['isLeadership']);
        // Сводка по МОП у него — ровно одна строка, своя.
        $this->assertCount(1, $page['props']['byManager']);
        $this->assertSame($mine->id, $page['props']['byManager'][0]['manager_id']);
    }

    public function test_manager_never_receives_company_profit_or_margin(): void
    {
        $mine = $this->user('manager');
        Deal::create(['number' => 'D-P', 'name' => 'Моя', 'company_name' => 'Моя',
            'budget' => 1000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
            'responsible_user_id' => $mine->id]);

        $props = $this->actingAs($mine)->get(route('reports.deals'))->assertOk()
            ->viewData('page')['props'];

        // Прибыль фирмы не должна доехать даже в props — не только скрыться в вёрстке.
        $this->assertArrayNotHasKey('company', $props['totals']);
        $this->assertArrayNotHasKey('margin', $props['totals']);
        $this->assertArrayNotHasKey('company', $props['rows'][0]);
        $this->assertArrayNotHasKey('margin', $props['rows'][0]);
        $this->assertArrayNotHasKey('company', $props['byManager'][0]);
        // А свой бонус и оборот — на месте, ради них отчёт и открывается.
        $this->assertArrayHasKey('bonus', $props['byManager'][0]);
        $this->assertArrayHasKey('budget', $props['byManager'][0]);
    }

    public function test_leadership_still_sees_company_profit(): void
    {
        Deal::create(['number' => 'D-L', 'name' => 'Сделка', 'company_name' => 'Сделка',
            'budget' => 1000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id]);

        $props = $this->actingAs($this->user('director'))->get(route('reports.deals'))
            ->assertOk()->viewData('page')['props'];

        $this->assertArrayHasKey('company', $props['totals']);
        $this->assertArrayHasKey('margin', $props['totals']);
    }

    public function test_report_breaks_deals_down_by_stage(): void
    {
        $admin = $this->user('admin');
        $stages = DealStage::orderBy('order')->take(2)->get();

        foreach ([[$stages[0], 2], [$stages[1], 1]] as [$stage, $count]) {
            foreach (range(1, $count) as $i) {
                Deal::create([
                    'number' => 'S-'.$stage->id.'-'.$i, 'name' => 'Сделка', 'company_name' => 'Сделка',
                    'budget' => 1000, 'status' => 'active', 'deal_stage_id' => $stage->id,
                ]);
            }
        }

        $page = $this->actingAs($admin)->get(route('reports.deals'))->assertOk()->viewData('page');

        $byStage = collect($page['props']['byStage'])->keyBy('stage_id');
        $this->assertSame(2, $byStage[$stages[0]->id]['count']);
        $this->assertSame(1, $byStage[$stages[1]->id]['count']);
        $this->assertEqualsWithDelta(2000, $byStage[$stages[0]->id]['budget'], 0.01);
    }

    public function test_report_renders_with_data_and_filters(): void
    {
        Deal::create([
            'number' => 'BAIA-R-1', 'name' => 'ТОО Тест', 'company_name' => 'ТОО Тест',
            'client_name' => 'парта', 'bin' => '990440002867', 'address' => 'Алматы',
            'budget' => 1966700, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);

        $this->actingAs($this->user('admin'))
            ->get(route('reports.deals', ['search' => 'ТОО', 'from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk();
    }
}
