<?php

namespace Tests\Feature;

use App\Models\CashReceipt;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    public function test_financist_adds_receipt_and_balances_grow(): void
    {
        $fin = $this->user('financist');

        $this->actingAs($fin)->post(route('finance.receipts.store'), [
            'amount' => 500000, 'method' => 'cash', 'source' => 'Учредитель', 'date' => now()->toDateString(),
            'note' => 'взнос',
        ])->assertRedirect();

        $this->assertEquals(1, CashReceipt::count());

        $this->actingAs($fin)->get(route('finance.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('summary.cash', 500000)
                ->where('summary.incomeManual', 500000)
                ->has('receipts', 1));
    }

    public function test_manager_cannot_add_or_delete_receipt(): void
    {
        $mgr = $this->user('manager');

        $this->actingAs($mgr)->post(route('finance.receipts.store'), [
            'amount' => 100, 'method' => 'bank', 'source' => 'x', 'date' => now()->toDateString(),
        ])->assertForbidden();

        $r = CashReceipt::create(['amount' => 100, 'method' => 'bank', 'source' => 'x', 'date' => now()->toDateString()]);
        $this->actingAs($mgr)->delete(route('finance.receipts.destroy', $r))->assertForbidden();
    }

    public function test_financist_can_edit_deal_and_add_expense_and_invoice(): void
    {
        // «Доступ менеджера финансисту»: редактирует чужую сделку, вносит
        // расход и счёт (аванс) сам — без участия менеджера.
        $fin = $this->user('financist');
        $mgr = $this->user('manager');
        $deal = Deal::create([
            'number' => 'D-1', 'name' => 'ТОО', 'company_name' => 'ТОО', 'client_name' => 'товар',
            'budget' => 100000, 'status' => 'active', 'responsible_user_id' => $mgr->id,
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);

        $this->actingAs($fin)->put(route('deals.update', $deal), [
            'client_name' => 'товар', 'company_name' => 'ТОО Новое', 'address' => 'Алматы', 'budget' => 120000,
        ])->assertRedirect();
        $this->assertEquals('ТОО Новое', $deal->fresh()->company_name);

        $this->actingAs($fin)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'amount' => 5000, 'date' => now()->toDateString(), 'payment_method' => 'cash', 'status' => 'confirmed',
        ])->assertRedirect();
        $this->assertEquals('confirmed', $deal->expenses()->first()->status);

        $this->actingAs($fin)->post(route('invoices.store'), [
            'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id,
            'amount' => 50000, 'issue_date' => now()->toDateString(),
        ])->assertRedirect();
        $this->assertEquals(1, $deal->invoices()->count());
    }
}
