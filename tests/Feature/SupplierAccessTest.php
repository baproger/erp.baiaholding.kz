<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Правило владельца от 22.09.2026: завсклад (supplier) видит Просроченные
 * сделки (ВСЕ, а не только свои этапы), Цех и Склад.
 * Заодно закрывает баг: на «Просроченных» сужение по stage_type применялось
 * к заказам цеха, где такой колонки нет → SQL-ошибка у supplier/designer.
 */
class SupplierAccessTest extends TestCase
{
    use RefreshDatabase;

    private Company $baia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $this->baia = Company::where('code', 'BAIA')->firstOrFail();
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->companies()->attach($this->baia->id);

        return $u;
    }

    /** Просроченная сделка чужого менеджера + просроченный заказ цеха. */
    private function seedOverdue(): array
    {
        $mgr = $this->user('manager');
        $deal = Deal::create([
            'company_id' => $this->baia->id, 'number' => 'BAIA-777', 'name' => 'Горит', 'company_name' => 'ТОО',
            'budget' => 100000, 'status' => 'active', 'deadline' => now()->subDays(5)->toDateString(),
            'deal_stage_id' => DealStage::orderBy('order')->first()->id, 'responsible_user_id' => $mgr->id,
        ]);
        $stage = ProjectStage::create(['name' => 'Кесу', 'order' => 1, 'is_active' => true, 'workshop' => 'Металл цех']);
        $project = Project::create([
            'number' => 'PRJ-777', 'name' => 'Заказ', 'deal_id' => $deal->id, 'workshop' => 'Металл цех',
            'project_stage_id' => $stage->id, 'status' => 'active',
            'deadline' => now()->subDays(3)->toDateString(), 'responsible_user_id' => $mgr->id,
        ]);

        return [$deal, $project];
    }

    public function test_supplier_sees_all_overdue_deals_and_projects(): void
    {
        [$deal, $project] = $this->seedOverdue();
        $supplier = $this->user('supplier');

        $this->actingAs($supplier)->withSession(['company_id' => $this->baia->id])
            ->get(route('deals.overdue'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('deals', 1)->where('deals.0.id', $deal->id)
                ->has('projects', 1)->where('projects.0.id', $project->id));
    }

    /** Тот же запрос у дизайнера раньше падал SQL-ошибкой по stage_type. */
    public function test_designer_overdue_page_does_not_crash(): void
    {
        $this->seedOverdue();
        $this->actingAs($this->user('designer'))->withSession(['company_id' => $this->baia->id])
            ->get(route('deals.overdue'))->assertOk();
    }

    public function test_manager_still_sees_only_own_overdue(): void
    {
        [$deal] = $this->seedOverdue();
        $other = $this->user('manager'); // чужой менеджер

        $this->actingAs($other)->withSession(['company_id' => $this->baia->id])
            ->get(route('deals.overdue'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('deals', 0)->has('projects', 0));

        // А ответственный свою сделку видит.
        $owner = User::find($deal->responsible_user_id);
        $this->actingAs($owner)->withSession(['company_id' => $this->baia->id])
            ->get(route('deals.overdue'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('deals', 1));
    }

    public function test_supplier_opens_workshop_list_and_order_card(): void
    {
        [, $project] = $this->seedOverdue();
        $supplier = $this->user('supplier');

        $this->actingAs($supplier)->withSession(['company_id' => $this->baia->id])
            ->get(route('projects.index'))->assertOk();
        // Раньше карточка чужого заказа давала 403.
        $this->actingAs($supplier)->withSession(['company_id' => $this->baia->id])
            ->get(route('projects.show', $project->id))->assertOk();
    }

    public function test_supplier_opens_warehouse(): void
    {
        $this->actingAs($this->user('supplier'))->withSession(['company_id' => $this->baia->id])
            ->get(route('warehouse.index'))->assertOk();
    }
}
