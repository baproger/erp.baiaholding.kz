<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PayrollTest extends TestCase
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

    // A deal on the «Оплата успешно» (is_won) stage is factual money → counts in payroll.
    private function wonDealWithFinance(User $mgr, float $paid, float $expense): Deal
    {
        $wonStage = DealStage::where('is_won', true)->first()->id;
        $deal = Deal::create(['number' => 'D-'.uniqid(), 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И', 'budget' => 1000000, 'status' => 'closed', 'deal_stage_id' => $wonStage, 'responsible_user_id' => $mgr->id]);
        $inv = Invoice::create(['number' => 'I-'.uniqid(), 'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id, 'amount' => $paid, 'status' => 'paid']);
        Payment::create(['invoice_id' => $inv->id, 'amount' => $paid, 'payment_date' => now()->toDateString()]);
        Expense::create(['expenseable_type' => 'deal', 'expenseable_id' => $deal->id, 'amount' => $expense, 'date' => now()->toDateString(), 'status' => 'confirmed']);
        return $deal;
    }

    public function test_bonus_is_ten_percent_of_net_profit(): void
    {
        $admin = $this->user('admin');
        $mgr = $this->user('manager');
        // budget 1M − tax 3% (30k) − expenses 100k = remainder 870k → bonus 87k, company 783k.
        $this->wonDealWithFinance($mgr, 500000, 100000);

        $this->actingAs($admin)->get(route('payroll.index'))
            ->assertInertia(fn (Assert $p) => $p->component('Payroll/Index')
                ->where('leadership', true)->where('rate', 10)
                ->where('rows.0.net', 783000)
                ->where('rows.0.bonus', 87000)
                ->where('rows.0.company', 783000));
    }

    public function test_manager_sees_only_own(): void
    {
        $mgr = $this->user('manager');
        $other = $this->user('manager');
        $this->wonDealWithFinance($mgr, 500000, 100000);
        $this->wonDealWithFinance($other, 900000, 100000);

        $this->actingAs($mgr)->get(route('payroll.index'))
            ->assertInertia(fn (Assert $p) => $p->where('leadership', false)->has('rows', 1)->where('rows.0.bonus', 87000));
    }

    public function test_unsuccessful_deal_not_counted(): void
    {
        $admin = $this->user('admin');
        $mgr = $this->user('manager');
        // Active deal at a NON-won stage, with a payment, never sent to Цех → not counted.
        $stage = DealStage::where('is_won', false)->orderBy('order')->first()->id;
        $deal = Deal::create(['number' => 'N-1', 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И', 'budget' => 500000, 'status' => 'active', 'deal_stage_id' => $stage, 'responsible_user_id' => $mgr->id]);
        $inv = Invoice::create(['number' => 'N-I', 'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id, 'amount' => 200000, 'status' => 'paid']);
        Payment::create(['invoice_id' => $inv->id, 'amount' => 200000, 'payment_date' => now()->toDateString()]);

        $this->actingAs($admin)->get(route('payroll.index'))
            ->assertInertia(fn (Assert $p) => $p->where('totals.bonus', 0));
    }

    // Цех employees may see their OWN salary only (no company-wide figures, no other people's rows).
    public function test_cex_employee_sees_only_own_salary(): void
    {
        $emp = $this->user('employee');
        $mgr = $this->user('manager');
        $this->wonDealWithFinance($mgr, 500000, 100000); // belongs to a manager, not the employee

        $this->actingAs($emp)->get(route('payroll.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('leadership', false)->has('rows', 0));
    }
}
