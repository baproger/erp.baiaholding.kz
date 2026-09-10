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

/** Разбивка «К выплате» на странице Бонусы должна открываться без ошибок. */
class BonusCarryTest extends TestCase
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

    public function test_carry_breakdown_returns_json_with_pending_deals(): void
    {
        $this->withoutExceptionHandling();
        $admin = $this->user('admin');
        $mgr = $this->user('manager');

        $won = DealStage::where('is_won', true)
            ->orderByRaw('company_id = '.$this->baia->id.' desc')->firstOrFail();
        Deal::create([
            'company_id' => $this->baia->id, 'number' => 'BAIA-001', 'name' => 'Сделка',
            'company_name' => 'ТОО Клиент', 'budget' => 1000000, 'status' => 'active',
            'deal_stage_id' => $won->id, 'responsible_user_id' => $mgr->id,
        ]);

        $res = $this->actingAs($admin)
            ->withSession(['company_id' => $this->baia->id])
            ->getJson(route('payroll.bonuses.carry', ['user' => $mgr->id]));

        $res->assertOk()->assertJsonStructure(['earned', 'pending', 'paid', 'earned_sum', 'paid_sum', 'balance']);
        // Клиент не оплатил ни тенге — сделка в «ждёт оплаты», бонус не начислен.
        $this->assertCount(1, $res->json('pending'));
        $this->assertSame('BAIA-001', $res->json('pending.0.number'));
    }

    public function test_manager_sees_only_own_breakdown(): void
    {
        $mgr = $this->user('manager');
        $other = $this->user('manager');

        $res = $this->actingAs($mgr)
            ->withSession(['company_id' => $this->baia->id])
            ->getJson(route('payroll.bonuses.carry', ['user' => $other->id]));

        $res->assertOk();
        $this->assertSame([], $res->json('earned'));
    }

    /**
     * Правило от 10.09.2026: ступень бонуса — по ЧИСТОЙ марже (остаток/сумма,
     * налог обратно не прибавляется). Кейс ASU-194: чистая маржа ~8% → бонус 0.
     */
    public function test_bonus_tier_uses_net_margin_tax_not_added_back(): void
    {
        // Сумма 1 000 000, расходы 885 000, налог 3% = 30 000 → остаток 85 000
        // Чистая маржа 8.5% → «до 10%» → бонус 0 (старая формула дала бы 11.5% → 5%).
        $this->assertSame(8.5, \App\Services\PayrollService::marginPct(1000000, 85000, 30000));
        $this->assertSame(0.0, \App\Services\PayrollService::marginBonus(1000000, 85000, 30000));

        // Остаток 120 000 → чистая маржа 12% → ступень 5% от остатка = 6 000.
        $this->assertSame(6000.0, \App\Services\PayrollService::marginBonus(1000000, 120000, 30000));

        // Остаток 450 000 → 45% → 15% от остатка = 67 500.
        $this->assertSame(67500.0, \App\Services\PayrollService::marginBonus(1000000, 450000, 30000));
    }
}
