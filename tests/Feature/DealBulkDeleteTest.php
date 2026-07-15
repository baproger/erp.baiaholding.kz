<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    private function deal(string $n): Deal
    {
        return Deal::create([
            'number' => $n, 'name' => 'ТОО', 'company_name' => 'ТОО', 'client_name' => 'товар',
            'budget' => 100, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    public function test_admin_bulk_deletes_deals(): void
    {
        $admin = $this->user('admin');
        $a = $this->deal('D-1');
        $b = $this->deal('D-2');
        $keep = $this->deal('D-3');

        $this->actingAs($admin)->delete(route('deals.bulkDestroy'), ['ids' => [$a->id, $b->id]])
            ->assertRedirect();

        $this->assertSoftDeleted('deals', ['id' => $a->id]);
        $this->assertSoftDeleted('deals', ['id' => $b->id]);
        $this->assertNull($keep->fresh()->deleted_at);
    }

    public function test_manager_cannot_bulk_delete(): void
    {
        $mgr = $this->user('manager');
        $a = $this->deal('D-1');

        $this->actingAs($mgr)->delete(route('deals.bulkDestroy'), ['ids' => [$a->id]])
            ->assertForbidden();
        $this->assertNull($a->fresh()->deleted_at);
    }
}
