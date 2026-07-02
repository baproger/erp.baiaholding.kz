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

    private function dealWithFinance(User $mgr, float $paid, float $expense): Deal
    {
        $deal = Deal::create(['number' => 'D-'.uniqid(), 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И', 'budget' => 1000000, 'status' => 'closed', 'deal_stage_id' => DealStage::orderBy('order')->first()->id, 'responsible_user_id' => $mgr->id]);
        $inv = Invoice::create(['number' => 'I-'.uniqid(), 'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id, 'amount' => $paid, 'status' => 'paid']);
        Payment::create(['invoice_id' => $inv->id, 'amount' => $paid, 'payment_date' => now()->toDateString()]);
        Expense::create(['expenseable_type' => 'deal', 'expenseable_id' => $deal->id, 'amount' => $expense, 'date' => now()->toDateString(), 'status' => 'confirmed']);
        return $deal;
    }

    public function test_bonus_is_ten_percent_of_net_profit(): void
    {
        $admin = $this->user('admin');
        $mgr = $this->user('manager');
        $this->dealWithFinance($mgr, 500000, 100000); // net 400k -> bonus 40k, company 360k

        $this->actingAs($admin)->get(route('payroll.index'))
            ->assertInertia(fn (Assert $p) => $p->component('Payroll/Index')
                ->where('leadership', true)->where('rate', 10)
                ->where('rows.0.net', 400000)
                ->where('rows.0.bonus', 40000)
                ->where('rows.0.company', 360000));
    }

    public function test_manager_sees_only_own(): void
    {
        $mgr = $this->user('manager');
        $other = $this->user('manager');
        $this->dealWithFinance($mgr, 500000, 100000);
        $this->dealWithFinance($other, 900000, 100000);

        $this->actingAs($mgr)->get(route('payroll.index'))
            ->assertInertia(fn (Assert $p) => $p->where('leadership', false)->has('rows', 1)->where('rows.0.bonus', 40000));
    }

    public function test_cex_employee_forbidden(): void
    {
        $emp = $this->user('employee');
        $this->actingAs($emp)->get(route('payroll.index'))->assertForbidden();
    }
}
