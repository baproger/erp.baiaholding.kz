<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\User;
use App\Services\PayrollService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Подрядные сделки (правило владельца от 30.09.2026): заводит бухгалтер/админ,
 * работу делает подрядчик, наш доход — %, остальное перечисляется подрядчику
 * (заявка-расход), бонусов нет, производственные этапы пропускаются.
 */
class ContractorDealTest extends TestCase
{
    use RefreshDatabase;

    private array $st = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $i = 1;
        foreach ([
            'contract' => 'Заключение договора', 'design' => 'Дизайн и расчет', 'shop_gate' => 'Закуп ЛДСП,МДФ',
            'logistics' => 'Логистика', 'assembly' => 'Сборка', 'act' => 'Акт утверждение', 'esf' => 'ЭСФ',
            'pay' => 'Оплата успешно', 'payment_won' => 'Тендер закрыт',
        ] as $type => $name) {
            $this->st[$type] = DealStage::create(['name' => $name, 'order' => $i++, 'is_active' => true,
                'stage_type' => $type === 'pay' ? null : $type, 'is_won' => $type === 'payment_won']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->companies()->attach(Company::where('code', 'BAIA')->firstOrFail()->id);

        return $u;
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'company_id' => Company::where('code', 'BAIA')->firstOrFail()->id,
            'company_name' => 'ТОО Заказчик', 'client_name' => 'Поставка окон', 'contractor_name' => 'ИП Подрядчик',
            'budget' => 1000000, 'commission_pct' => 10,
        ], $over);
    }

    private function createByAccountant(array $over = []): Deal
    {
        $fin = $this->user('financist');
        $this->actingAs($fin)->post(route('deals.contractor.store'), $this->payload($over))->assertRedirect();

        return Deal::latest('id')->firstOrFail();
    }

    public function test_accountant_creates_contractor_deal_with_payout_request_and_no_bonus(): void
    {
        $deal = $this->createByAccountant();

        $this->assertSame('contractor', $deal->kind);
        $this->assertSame('ИП Подрядчик', $deal->contractor_name);
        $this->assertSame(100000.0, $deal->commissionSum());
        $this->assertSame(900000.0, $deal->contractorPayout());
        $this->assertSame(0.0, (float) $deal->partner_pct);
        $this->assertSame(0.0, (float) $deal->bonus_rate_override, 'бонус никому');
        $this->assertSame($this->st['contract']->id, $deal->deal_stage_id);

        $payout = Expense::where('expenseable_id', $deal->id)->where('type', 'contractor')->firstOrFail();
        $this->assertSame('pending', $payout->status);
        $this->assertSame(900000.0, (float) $payout->amount);
        $this->assertStringContainsString('ИП Подрядчик', $payout->description);

        // Бонус по формуле ЗП — 0 даже при высокой марже.
        $this->assertSame(0.0, PayrollService::marginBonus(1000000, 70000, 30000, 0.0));
    }

    public function test_manager_and_director_cannot_create_contractor_deal(): void
    {
        $this->actingAs($this->user('manager'))->post(route('deals.contractor.store'), $this->payload())->assertForbidden();
        $this->actingAs($this->user('director'))->post(route('deals.contractor.store'), $this->payload())->assertForbidden();
        $this->assertSame(0, Deal::count());
    }

    public function test_advance_skips_production_stages(): void
    {
        $deal = $this->createByAccountant();
        $fin = User::role('financist')->firstOrFail();

        $expected = ['act', 'esf', 'pay', 'payment_won'];
        foreach ($expected as $type) {
            $this->actingAs($fin)->patch(route('deals.advance', $deal->id))->assertSessionHas('success');
            $this->assertSame($this->st[$type]->id, $deal->fresh()->deal_stage_id, "ожидался этап {$type}");
        }
    }

    public function test_cannot_move_contractor_deal_to_production_stage_or_workshop(): void
    {
        $deal = $this->createByAccountant();
        $fin = User::role('financist')->firstOrFail();

        foreach (['design', 'shop_gate', 'logistics', 'assembly'] as $type) {
            $this->actingAs($fin)->patch(route('deals.stage', $deal->id), ['deal_stage_id' => $this->st[$type]->id])
                ->assertSessionHas('error');
            $this->assertSame($this->st['contract']->id, $deal->fresh()->deal_stage_id);
        }

        $this->actingAs($fin)->post(route('deals.toWorkshop', $deal->id), ['workshop' => 'Металл цех'])->assertSessionHas('error');
        $this->assertNull($deal->fresh()->project);
        $this->assertSame('active', $deal->fresh()->status);
    }

    public function test_payout_request_follows_budget_and_percent_until_confirmed(): void
    {
        $deal = $this->createByAccountant();
        $fin = User::role('financist')->firstOrFail();
        $payout = Expense::where('expenseable_id', $deal->id)->where('type', 'contractor')->firstOrFail();

        $this->actingAs($fin)->put(route('deals.update', $deal->id), [
            'company_name' => 'ТОО Заказчик', 'client_name' => 'Поставка окон', 'address' => 'Астана',
            'budget' => 2000000, 'commission_pct' => 15, 'contractor_name' => 'ИП Подрядчик',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1700000.0, (float) $payout->fresh()->amount, '2 000 000 − 15%');
        $this->assertSame(0.0, (float) $deal->fresh()->partner_pct, 'партнёрской доли у подряда нет');

        // Подтверждённую заявку изменение суммы не трогает.
        $payout->update(['status' => 'confirmed', 'payment_method' => 'bank', 'confirmed_by' => $fin->id, 'confirmed_at' => now()]);
        $this->actingAs($fin)->put(route('deals.update', $deal->id), [
            'company_name' => 'ТОО Заказчик', 'client_name' => 'Поставка окон', 'address' => 'Астана',
            'budget' => 3000000, 'commission_pct' => 15, 'contractor_name' => 'ИП Подрядчик',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1700000.0, (float) $payout->fresh()->amount);
        $this->assertSame(1, Expense::where('expenseable_id', $deal->id)->where('type', 'contractor')->count(), 'вторая заявка не плодится');
    }

    public function test_bonus_rate_cannot_be_set_on_contractor_deal(): void
    {
        $deal = $this->createByAccountant();
        $fin = User::role('financist')->firstOrFail();

        $this->actingAs($fin)->patch(route('deals.bonusRate', $deal->id), ['bonus_rate_override' => 10])->assertSessionHas('error');
        $this->assertSame(0.0, (float) $deal->fresh()->bonus_rate_override);
    }

    public function test_kind_filter_and_badge_data_on_index(): void
    {
        $deal = $this->createByAccountant();
        $fin = User::role('financist')->firstOrFail();

        $this->actingAs($fin)->get(route('deals.index', ['kind' => 'contractor']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Deals/Index')
                ->where('deals.0.id', $deal->id)
                ->where('deals.0.kind', 'contractor')
                ->where('deals.0.contractor_name', 'ИП Подрядчик')
                ->where('can.createContractor', true));
        $this->actingAs($fin)->get(route('deals.index', ['kind' => 'own']))
            ->assertInertia(fn ($page) => $page->where('deals', []));
    }
}
