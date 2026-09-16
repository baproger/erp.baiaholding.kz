<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\PreDeal;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** «План/Факт» по предсделкам: только админ и директор; разница = факт − план. */
class PlanFactReportTest extends TestCase
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

    public function test_access_admin_and_director_only(): void
    {
        $this->actingAs($this->user('admin'))->withSession(['company_id' => $this->baia->id])
            ->get(route('reports.planFact'))->assertOk();
        $this->actingAs($this->user('director'))->withSession(['company_id' => $this->baia->id])
            ->get(route('reports.planFact'))->assertOk();
        $this->actingAs($this->user('financist'))->get(route('reports.planFact'))->assertForbidden();
        $this->actingAs($this->user('manager'))->get(route('reports.planFact'))->assertForbidden();
    }

    public function test_diff_is_fact_minus_plan(): void
    {
        $mgr = $this->user('manager');
        $deal = Deal::create([
            'company_id' => $this->baia->id, 'number' => 'BAIA-001', 'name' => 'Сделка', 'company_name' => 'ТОО',
            'budget' => 1000000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id, 'responsible_user_id' => $mgr->id,
        ]);
        PreDeal::create([
            'company_id' => $this->baia->id, 'user_id' => $mgr->id, 'action' => 'participation',
            'lot_number' => 'L-1', 'customer' => 'ТОО', 'product' => 'Стол', 'status' => 'confirmed',
            'deal_id' => $deal->id, 'contract_sum' => 1000000,
            'purchase_price' => 300000, 'delivery' => 50000, 'assembly' => 50000, 'commission' => 0,
            'remainder' => 570000, 'margin' => 57,
        ]);
        Expense::create([
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id, 'amount' => 500000,
            'date' => now()->toDateString(), 'status' => 'confirmed', 'type' => 'direct',
            'responsible_user_id' => $mgr->id, 'description' => 'факт',
        ]);

        $res = $this->actingAs($this->user('admin'))->withSession(['company_id' => $this->baia->id])
            ->get(route('reports.planFact'))->assertOk();

        $res->assertInertia(fn ($page) => $page
            ->where('rows.0.plan.expense', 400000)
            ->where('rows.0.fact.expense', 500000)
            ->where('rows.0.diff.expense', 100000)   // потратили на 100k больше плана
            ->where('rows.0.fact.remainder', 470000) // 1М − 30k налог − 500k расходы
            ->where('rows.0.fact.margin', 47)
            // Маржа 47% → ставка 15%: бонус 70 500, фирме чистыми 399 500.
            ->where('rows.0.fact.bonus', 70500)
            ->where('rows.0.fact.net', 399500)
            // План: маржа 57% → 15% от 570 000 = 85 500, фирме 484 500.
            ->where('rows.0.plan.bonus', 85500)
            ->where('rows.0.plan.net', 484500)
            ->where('rows.0.diff.net', -85000));
    }
}
