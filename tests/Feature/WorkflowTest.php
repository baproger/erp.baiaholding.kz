<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function seedAll(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    public function test_manager_can_create_deal(): void
    {
        $this->seedAll();
        $emp = $this->user('manager');

        $this->actingAs($emp)->post(route('deals.store'), ['name' => 'Тендер', 'client_name' => 'Иван', 'company_name' => 'ТОО Тендер', 'address' => 'Астана, пр. Мәңгілік Ел 1', 'budget' => 1000000])
            ->assertRedirect();
        $this->assertEquals(1, Deal::count());
    }

    public function test_advance_moves_to_next_stage(): void
    {
        $this->seedAll();
        $admin = $this->user('admin');
        $first = DealStage::orderBy('order')->first();
        $second = DealStage::orderBy('order')->skip(1)->first();
        $deal = Deal::create(['number' => 'BAIA-W-1', 'name' => 'D', 'budget' => 1, 'status' => 'active', 'deal_stage_id' => $first->id]);

        $this->actingAs($admin)->patch(route('deals.advance', $deal))->assertRedirect();
        $this->assertEquals($second->id, $deal->fresh()->deal_stage_id);
    }

    public function test_send_to_workshop_creates_project_at_first_cex_stage(): void
    {
        $this->seedAll();
        $admin = $this->user('admin');
        $deal = Deal::create(['number' => 'BAIA-W-2', 'name' => 'Заказ', 'budget' => 1000000, 'status' => 'active', 'deal_stage_id' => DealStage::orderBy('order')->first()->id]);

        $this->actingAs($admin)->post(route('deals.toWorkshop', $deal))->assertRedirect();

        $project = Project::first();
        $this->assertNotNull($project);
        $firstCex = ProjectStage::orderBy('order')->first();
        $this->assertEquals('Кесу', $firstCex->name);
        $this->assertEquals($firstCex->id, $project->project_stage_id);
        $this->assertEquals('closed', $deal->fresh()->status);
    }

    public function test_admin_can_manage_stages(): void
    {
        $this->seedAll();
        $admin = $this->user('admin');

        $this->actingAs($admin)->post(route('stages.store'), ['kind' => 'project', 'name' => 'Покраска', 'color' => '#123456'])
            ->assertRedirect();
        $stage = ProjectStage::where('name', 'Покраска')->first();
        $this->assertNotNull($stage);

        $this->actingAs($admin)->put(route('stages.update', ['project', $stage->id]), ['name' => 'Лакировка'])->assertRedirect();
        $this->assertEquals('Лакировка', $stage->fresh()->name);

        $this->actingAs($admin)->delete(route('stages.destroy', ['project', $stage->id]))->assertRedirect();
        $this->assertNull(ProjectStage::find($stage->id));
    }

    public function test_stage_management_forbidden_for_employee(): void
    {
        $this->seedAll();
        $emp = $this->user('employee');
        $this->actingAs($emp)->get(route('stages.index'))->assertForbidden();
    }
}
