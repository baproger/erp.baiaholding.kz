<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CexAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    public function test_workshop_employee_sees_all_cex_cards(): void
    {
        $emp = $this->user('employee');
        $other = $this->user('manager');
        $s = ProjectStage::orderBy('order')->first()->id;
        Project::create(['number' => 'P-1', 'name' => 'Мой', 'budget' => 1, 'status' => 'active', 'project_stage_id' => $s, 'responsible_user_id' => $emp->id]);
        Project::create(['number' => 'P-2', 'name' => 'Чужой', 'budget' => 1, 'status' => 'active', 'project_stage_id' => $s, 'responsible_user_id' => $other->id]);

        $this->actingAs($emp)->get(route('projects.index'))
            ->assertInertia(fn (Assert $p) => $p->component('Projects/Index')->has('projects', 2));
    }

    public function test_workshop_employee_can_advance_process(): void
    {
        $emp = $this->user('employee');
        $stages = ProjectStage::orderBy('order')->take(2)->get();
        $project = Project::create(['number' => 'P-3', 'name' => 'X', 'budget' => 1, 'status' => 'active', 'project_stage_id' => $stages[0]->id, 'responsible_user_id' => null]);

        $this->actingAs($emp)->patch(route('projects.advance', $project))->assertRedirect();
        $this->assertEquals($stages[1]->id, $project->fresh()->project_stage_id);
    }

    public function test_manager_finance_scoped_to_own(): void
    {
        $mgr = $this->user('manager');
        $other = $this->user('manager');
        $s = DealStage::orderBy('order')->first()->id;
        $mine = Deal::create(['number' => 'D-1', 'name' => 'Мой', 'budget' => 1, 'status' => 'active', 'deal_stage_id' => $s, 'responsible_user_id' => $mgr->id]);
        $theirs = Deal::create(['number' => 'D-2', 'name' => 'Чужой', 'budget' => 1, 'status' => 'active', 'deal_stage_id' => $s, 'responsible_user_id' => $other->id]);
        Invoice::create(['number' => 'INV-1', 'invoiceable_type' => 'deal', 'invoiceable_id' => $mine->id, 'amount' => 1000, 'status' => 'sent']);
        Invoice::create(['number' => 'INV-2', 'invoiceable_type' => 'deal', 'invoiceable_id' => $theirs->id, 'amount' => 5000, 'status' => 'sent']);

        $this->actingAs($mgr)->get(route('finance.index'))
            ->assertInertia(fn (Assert $p) => $p->has('invoices.data', 1)->where('totals.invoiced', 1000));
    }
}
