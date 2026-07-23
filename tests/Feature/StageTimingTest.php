<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\ProjectStage;
use App\Models\ProjectStageLog;
use App\Models\User;
use App\Models\WorkshopScreen;
use App\Services\ProjectService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Тайминг этапов цеха + экран «Офис» (лидеры менеджеров). */
class StageTimingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    public function test_stage_timing_logged_per_stage(): void
    {
        $company = Company::firstOrCreate(['code' => 'BAIA'], ['name' => 'BAIA']);
        $s1 = ProjectStage::create(['company_id' => $company->id, 'name' => 'Кесу', 'order' => 1, 'type' => 'project', 'is_active' => true]);
        $s2 = ProjectStage::create(['company_id' => $company->id, 'name' => 'Жинау', 'order' => 2, 'type' => 'project', 'is_active' => true]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $deal = Deal::create(['number' => 'BAIA-001', 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И', 'budget' => 1, 'status' => 'active', 'company_id' => $company->id, 'deal_stage_id' => DealStage::orderBy('order')->first()->id]);
        $project = app(ProjectService::class)->createFromDeal($deal);

        // Вход в цех — таймер первого этапа открыт.
        $open = ProjectStageLog::where('project_id', $project->id)->whereNull('left_at')->get();
        $this->assertCount(1, $open);
        $this->assertSame('Кесу', $open->first()->stage_name);

        // «Далее» — старый таймер закрыт с длительностью, новый открыт.
        $this->actingAs($admin)->patch(route('projects.advance', $project->id));
        $logs = ProjectStageLog::where('project_id', $project->id)->orderBy('entered_at')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertNotNull($logs[0]->left_at);
        $this->assertNotNull($logs[0]->duration_seconds);
        $this->assertSame('Жинау', $logs[1]->stage_name);
        $this->assertNull($logs[1]->left_at);

        // История таймингов видна на карточке заказа.
        $this->actingAs($admin)->get(route('projects.show', $project->id))
            ->assertInertia(fn (Assert $p) => $p->has('stageLogs', 2)->where('stageLogs.1.open', true));
    }

    public function test_office_leader_by_efficiency_not_by_count(): void
    {
        $company = Company::firstOrCreate(['code' => 'BAIA'], ['name' => 'BAIA']);
        $stage = DealStage::orderBy('order')->first()->id;
        $wonStage = DealStage::where('is_won', true)->first()->id;

        // A: ОДНА успешная сделка с высокой маржой — прибыль для компании есть.
        $a = User::factory()->create(['name' => 'Эффективный']);
        $a->assignRole('manager');
        $deal = Deal::create(['number' => 'BAIA-001', 'name' => 'X', 'company_name' => 'Т', 'client_name' => 'И', 'budget' => 1000000, 'status' => 'closed', 'company_id' => $company->id, 'deal_stage_id' => $wonStage, 'responsible_user_id' => $a->id]);
        $inv = \App\Models\Invoice::create(['number' => 'I-1', 'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id, 'amount' => 1000000, 'status' => 'paid']);
        \App\Models\Payment::create(['invoice_id' => $inv->id, 'amount' => 1000000, 'payment_date' => now()->toDateString()]);
        \App\Models\Expense::create(['expenseable_type' => 'deal', 'expenseable_id' => $deal->id, 'amount' => 100000, 'date' => now()->toDateString(), 'status' => 'confirmed']);

        // B: ТРИ сделки, но ни одной успешной — количеством лидером не стать.
        $b = User::factory()->create(['name' => 'Количество']);
        $b->assignRole('manager');
        foreach ([2, 3, 4] as $i) {
            Deal::create(['number' => 'BAIA-00'.$i, 'name' => 'X', 'company_name' => 'Т', 'client_name' => 'И', 'budget' => 500000, 'status' => 'active', 'company_id' => $company->id, 'deal_stage_id' => $stage, 'responsible_user_id' => $b->id]);
        }

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->post(route('workshopScreens.upsert'), ['company_id' => $company->id, 'kind' => 'office'])->assertRedirect();
        $code = WorkshopScreen::where('kind', 'office')->firstOrFail()->code;

        auth()->logout();
        $this->post(route('screen.enter'), ['code' => $code]);
        $this->get(route('screen.show'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Screen/Office')
            ->where('leader.name', 'Эффективный')
            ->where('managers.0.score', 100)
            ->where('managers.0.won', 1)
            ->where('managers.1.name', 'Количество')
            ->where('managers.1.score', 0)
            ->where('managers.1.total', 3));

        // Фильтр месяца: в прошлом месяце успехов не было — лидера нет (score 0).
        $this->get(route('screen.show', ['month' => now()->subMonthNoOverflow()->format('Y-m')]))
            ->assertInertia(fn (Assert $p) => $p->where('managers.0.won', 0));
    }
}
