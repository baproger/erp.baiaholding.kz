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

    public function test_office_screen_shows_leaders(): void
    {
        $company = Company::firstOrCreate(['code' => 'BAIA'], ['name' => 'BAIA']);
        $mgr = User::factory()->create(['name' => 'Лидер']);
        $mgr->assignRole('manager');
        $stage = DealStage::orderBy('order')->first()->id;
        foreach ([1, 2] as $i) {
            Deal::create(['number' => 'BAIA-00'.$i, 'name' => 'X', 'company_name' => 'ТОО '.$i, 'client_name' => 'И', 'budget' => 1, 'status' => 'active', 'company_id' => $company->id, 'deal_stage_id' => $stage, 'responsible_user_id' => $mgr->id]);
        }

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->post(route('workshopScreens.upsert'), ['company_id' => $company->id, 'kind' => 'office'])->assertRedirect();
        $code = WorkshopScreen::where('kind', 'office')->firstOrFail()->code;

        auth()->logout();
        $this->post(route('screen.enter'), ['code' => $code]);
        $this->get(route('screen.show'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Screen/Office')
            ->has('deals', 2)
            ->where('leaders.0.name', 'Лидер')
            ->where('leaders.0.total', 2));
    }
}
