<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\DealStageLog;
use App\Models\Document;
use App\Models\Expense;
use App\Models\PreDeal;
use App\Models\SupplierDebt;
use App\Models\User;
use App\Services\DealSupportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ТЗ 02.11.2026, п.6: «Сопровождение сделки» — 12 проверок, итог 🟢/🟡/🔴,
 * хранится в сделке (фильтр «красных»), маржа по всем расходам.
 */
class DealSupportTest extends TestCase
{
    use RefreshDatabase;

    private array $st = [];

    private Company $baia;

    private User $mgr;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 12:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->baia = Company::where('code', 'BAIA')->firstOrFail();
        $i = 1;
        foreach (['contract', 'design', 'shop_gate', 'logistics', 'assembly', 'act', 'esf', 'payment_won'] as $t) {
            $this->st[$t] = DealStage::create(['company_id' => $this->baia->id, 'name' => $t, 'order' => $i++, 'is_active' => true, 'stage_type' => $t, 'is_won' => $t === 'payment_won']);
        }
        $this->mgr = User::factory()->create();
        $this->mgr->assignRole('manager');
        $this->mgr->companies()->attach($this->baia->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Сделка, у которой всё в порядке (этап «Договор»). */
    private function goodDeal(string $stage = 'contract'): Deal
    {
        $d = Deal::create(['number' => 'BAIA-'.uniqid(), 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'Шкаф', 'address' => 'Астана',
            'bin' => 'Д-1', 'contract_date' => '2026-10-20', 'lot_number' => '2', 'budget' => 1000000, 'status' => 'active',
            'company_id' => $this->baia->id, 'deal_stage_id' => $this->st[$stage]->id, 'responsible_user_id' => $this->mgr->id, 'deadline' => '2026-12-01']);
        Document::create(['documentable_type' => 'deal', 'documentable_id' => $d->id, 'name' => 'Договор.pdf', 'kind' => 'contract',
            'file_path' => 'documents/d.pdf', 'version' => 1, 'size' => 1, 'mime_type' => 'application/pdf', 'is_active' => true]);

        return $d->fresh();
    }

    private function supportOf(Deal $d): string
    {
        return app(DealSupportService::class)->check($d->fresh())['status'];
    }

    private function state(Deal $d, string $key): string
    {
        return collect(app(DealSupportService::class)->check($d->fresh())['checks'])->firstWhere('key', $key)['state'];
    }

    public function test_clean_deal_is_green_and_status_is_stored(): void
    {
        $d = $this->goodDeal();
        $this->assertSame('green', $this->supportOf($d));
        $this->assertSame('green', $d->fresh()->support_status, 'итог сохранён в сделке (событие saved)');
    }

    public function test_red_violations(): void
    {
        $d = $this->goodDeal();
        Deal::whereKey($d->id)->update(['responsible_user_id' => null]);
        $this->assertSame('bad', $this->state($d, 'responsible'));
        $this->assertSame('red', $this->supportOf($d));

        $d2 = $this->goodDeal();
        Deal::whereKey($d2->id)->update(['deadline' => '2026-10-25']);
        $this->assertSame('bad', $this->state($d2, 'deadline'));

        $d3 = $this->goodDeal();
        // Подтверждённый расход совсем без документа (ни чека менеджера, ни чека оплаты).
        Expense::create(['expenseable_type' => 'deal', 'expenseable_id' => $d3->id, 'amount' => 5000, 'date' => today(), 'status' => 'confirmed', 'type' => 'delivery', 'payment_method' => 'cash']);
        $this->assertSame('bad', $this->state($d3, 'expense_docs'));
        $this->assertSame('red', $d3->fresh()->support_status, 'пересчитано по событию расхода');

        $d4 = $this->goodDeal();
        SupplierDebt::create(['company_id' => $this->baia->id, 'deal_id' => $d4->id, 'supplier_name' => 'ТОО Лес', 'amount' => 1000, 'due_date' => '2026-10-30', 'status' => 'active']);
        $this->assertSame('bad', $this->state($d4, 'debts'));

        $d5 = $this->goodDeal('shop_gate'); // дизайн пройден, сметы нет
        $this->assertSame('bad', $this->state($d5, 'estimate'));
    }

    public function test_yellow_remarks(): void
    {
        $d = $this->goodDeal();
        Expense::create(['expenseable_type' => 'deal', 'expenseable_id' => $d->id, 'amount' => 500, 'date' => today(), 'status' => 'confirmed', 'type' => 'direct', 'file_path' => 'r.jpg']);
        $this->assertSame('warn', $this->state($d, 'expense_small'));
        $this->assertSame('yellow', $this->supportOf($d));

        $d2 = $this->goodDeal();
        Deal::whereKey($d2->id)->update(['extended_deadline' => '2026-12-20', 'extensions_count' => 2]);
        $this->assertSame('warn', $this->state($d2, 'deadline'));

        $d3 = $this->goodDeal();
        AuditLog::create(['table_name' => 'deals', 'record_id' => $d3->id, 'action' => 'reopened', 'field_name' => 'reason', 'new_value' => 'x', 'created_at' => now()]);
        $this->assertSame('warn', $this->state($d3, 'reopen'));

        $d4 = $this->goodDeal();
        DealStageLog::create(['deal_id' => $d4->id, 'deal_stage_id' => $this->st['design']->id, 'stage_name' => 'Дизайн', 'entered_at' => now()->subMinutes(3), 'left_at' => now(), 'duration_seconds' => 180]);
        $this->assertSame('warn', $this->state($d4, 'fast_stage'));

        $e = Expense::create(['expenseable_type' => 'deal', 'expenseable_id' => $d->id, 'amount' => 20000, 'date' => today(), 'status' => 'pending', 'type' => 'direct', 'file_path' => 'r.jpg', 'created_at' => now()->subHours(50)]);
        Expense::whereKey($e->id)->update(['created_at' => now()->subHours(50)]);
        $this->assertSame('warn', $this->state($d, 'expense_confirm'));
    }

    public function test_stage_without_expense_and_not_required_flag(): void
    {
        $d = $this->goodDeal('act'); // Логистика и Сборка пройдены
        $this->giveEstimate($d);
        $this->assertSame('warn', $this->state($d, 'stage_expense'));

        $this->actingAs($this->mgr)->patch(route('deals.supportFlags', $d), ['logistics_not_required' => true, 'assembly_not_required' => true])->assertSessionHas('success');
        $this->assertSame('ok', $this->state($d, 'stage_expense'));
    }

    public function test_margin_uses_all_expenses_and_compares_with_plan(): void
    {
        $d = $this->goodDeal('logistics');
        $this->giveEstimate($d);
        PreDeal::create(['user_id' => $this->mgr->id, 'company_id' => $this->baia->id, 'customer' => 'ТОО', 'product' => 'Шкаф',
            'contract_sum' => 1000000, 'purchase_price' => 300000, 'margin' => 60, 'status' => 'confirmed', 'deal_id' => $d->id]);
        Expense::create(['expenseable_type' => 'deal', 'expenseable_id' => $d->id, 'amount' => 300000, 'date' => today(), 'status' => 'confirmed', 'type' => 'purchase', 'file_path' => 'r.jpg']);
        Expense::create(['expenseable_type' => 'deal', 'expenseable_id' => $d->id, 'amount' => 200000, 'date' => today(), 'status' => 'pending', 'type' => 'fittings', 'file_path' => 'r.jpg']);

        $res = app(DealSupportService::class)->check($d->fresh());
        // 1 000 000 − 3% налог − 500 000 (в т.ч. неподтверждённые 200 000) = 470 000 → 47%
        $this->assertSame(47.0, $res['margin']['fact_pct']);
        $this->assertSame(200000.0, $res['margin']['unconfirmed']);
        $this->assertSame('warn', collect($res['checks'])->firstWhere('key', 'margin')['state'], '47% против плана 60% — больше 10 п.п.');
    }

    public function test_list_filter_red_and_card_payload(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $good = $this->goodDeal();
        $bad = $this->goodDeal();
        Deal::whereKey($bad->id)->update(['deadline' => '2026-10-01']);
        $this->artisan('deals:support-refresh')->assertSuccessful();

        $this->actingAs($admin)->get(route('deals.index', ['support' => 'red']))
            ->assertInertia(fn (Assert $p) => $p->where('deals', fn ($ds) => collect($ds)->pluck('id')->all() === [$bad->id]));
        $this->actingAs($admin)->get(route('deals.show', $bad))
            ->assertInertia(fn (Assert $p) => $p->where('support.status', 'red')->has('support.checks', 12));
    }
}
