<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function deal(?int $responsibleId = null): Deal
    {
        return Deal::create([
            'number' => 'BAIA-P-1', 'name' => 'D', 'budget' => 1000, 'status' => 'active',
            'responsible_user_id' => $responsibleId,
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    public function test_responsible_without_permission_can_edit_deal(): void
    {
        $user = User::factory()->create(); // no role → no deal.update permission
        $this->assertFalse($user->can('deal.update'));

        $deal = $this->deal($user->id);

        $this->actingAs($user)->put(route('deals.update', $deal), [
            'name' => 'Отредактировано', 'client_name' => 'Иван', 'company_name' => 'ТОО', 'budget' => 2000,
        ])->assertRedirect();

        $this->assertEquals('Отредактировано', $deal->fresh()->name);
    }

    public function test_non_responsible_without_permission_cannot_edit(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $deal = $this->deal($owner->id);

        $this->actingAs($stranger)->put(route('deals.update', $deal), [
            'name' => 'Хакнуто', 'client_name' => 'И', 'company_name' => 'Х', 'budget' => 1,
        ])->assertForbidden();
    }

    public function test_any_user_can_change_responsible(): void
    {
        $someone = User::factory()->create();
        $newResp = User::factory()->create();
        $deal = $this->deal(null);

        $this->actingAs($someone)->patch(route('deals.responsible', $deal), [
            'responsible_user_id' => $newResp->id,
        ])->assertRedirect();

        $this->assertEquals($newResp->id, $deal->fresh()->responsible_user_id);
    }
}
