<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\User;
use App\Services\FinanceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Правило владельца от 22.09.2026: металл, взятый из цеха, подтверждает
 * завсклад (supplier) наравне с бухгалтером — БЕЗ чека и без кассы
 * (деньги ушли при закупе). Прочие расходы завсклад не трогает.
 */
class MetalConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private Company $baia;

    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $this->baia = Company::where('code', 'BAIA')->firstOrFail();
        $this->deal = Deal::create([
            'company_id' => $this->baia->id, 'number' => 'BAIA-001', 'name' => 'X', 'company_name' => 'ТОО',
            'budget' => 500000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
            'responsible_user_id' => $this->user('manager')->id,
        ]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->companies()->attach($this->baia->id);

        return $u;
    }

    private function expense(string $type): Expense
    {
        return Expense::create([
            'expenseable_type' => 'deal', 'expenseable_id' => $this->deal->id,
            'amount' => 40000, 'date' => now()->toDateString(), 'status' => 'pending',
            'type' => $type, 'responsible_user_id' => $this->deal->responsible_user_id,
            'description' => $type === 'metal' ? 'Металл из цеха' : 'Прочий расход',
        ]);
    }

    private function cash(): array
    {
        $b = app(FinanceService::class)->companyBalances($this->baia->id);

        return [(float) $b['cash'], (float) $b['bank']];
    }

    public function test_warehouse_keeper_confirms_metal_without_receipt_and_cash_untouched(): void
    {
        $supplier = $this->user('supplier');
        $metal = $this->expense('metal');
        $before = $this->cash();

        $this->actingAs($supplier)->withSession(['company_id' => $this->baia->id])
            ->patch(route('expenses.confirm', $metal->id))
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $metal->refresh();
        $this->assertSame('confirmed', $metal->status);
        $this->assertSame($supplier->id, $metal->confirmed_by);
        $this->assertNull($metal->payment_method, 'касса не выбирается — деньги ушли при закупе');
        $this->assertNull($metal->confirm_file_path, 'чек оплаты не требуется');
        $this->assertSame($before, $this->cash(), 'баланс кассы и банка не изменился');
    }

    public function test_accountant_confirms_metal_the_same_way(): void
    {
        $fin = $this->user('financist');
        $metal = $this->expense('metal');

        $this->actingAs($fin)->withSession(['company_id' => $this->baia->id])
            ->patch(route('expenses.confirm', $metal->id))->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $metal->fresh()->status);
        $this->assertNull($metal->fresh()->payment_method);
    }

    public function test_warehouse_keeper_cannot_confirm_ordinary_expense(): void
    {
        $supplier = $this->user('supplier');
        $other = $this->expense('direct');

        $this->actingAs($supplier)->withSession(['company_id' => $this->baia->id])
            ->patch(route('expenses.confirm', $other->id))->assertForbidden();
        $this->assertSame('pending', $other->fresh()->status);
    }

    public function test_ordinary_expense_still_requires_receipt_from_accountant(): void
    {
        $fin = $this->user('financist');
        $other = $this->expense('direct');

        $this->actingAs($fin)->withSession(['company_id' => $this->baia->id])
            ->patch(route('expenses.confirm', $other->id), ['payment_method' => 'cash'])
            ->assertSessionHasErrors('file');
        $this->assertSame('pending', $other->fresh()->status);
    }

    public function test_board_shows_only_metal_to_warehouse_keeper(): void
    {
        $supplier = $this->user('supplier');
        $metal = $this->expense('metal');
        $this->expense('direct');

        $res = $this->actingAs($supplier)->withSession(['company_id' => $this->baia->id])
            ->get(route('expenses.board'))->assertOk();

        $res->assertInertia(fn ($page) => $page
            ->has('pending', 1)
            ->where('pending.0.id', $metal->id)
            ->where('pending.0.type', 'metal')
            ->where('pending.0.can_confirm', true)
            ->where('canManage', false));
    }

    public function test_board_shows_everything_to_accountant_with_confirm_rights(): void
    {
        $fin = $this->user('financist');
        $this->expense('metal');
        $this->expense('direct');

        $this->actingAs($fin)->withSession(['company_id' => $this->baia->id])
            ->get(route('expenses.board'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pending', 2)
                ->where('pending.0.can_confirm', true)
                ->where('canManage', true));
    }
}
