<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\EmployeeDebt;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\EmployeeDebtService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Долг сотрудника гасится сам, каждый месяц, ФИКСИРОВАННОЙ суммой и ТОЛЬКО из
 * бонуса. Оклад не трогается ни при каких условиях.
 */
class EmployeeDebtTest extends TestCase
{
    use RefreshDatabase;

    private User $financist;

    private User $manager;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $this->company = Company::where('code', 'BAIA')->firstOrFail();
        $this->financist = User::factory()->create();
        $this->financist->assignRole('financist');
        $this->financist->companies()->attach($this->company->id);

        $this->manager = User::factory()->create(['salary' => 300000]);
        $this->manager->assignRole('manager');
        $this->manager->companies()->attach($this->company->id);
    }

    /** Выигранная и полностью оплаченная сделка = бонус менеджеру в этом месяце. */
    private function wonDeal(float $budget, string $contractDate): Deal
    {
        $wonStage = DealStage::where('is_won', true)->firstOrFail();
        $deal = Deal::create([
            'company_id' => $this->company->id, 'number' => 'BAIA-D-'.uniqid(),
            'name' => 'Сделка', 'company_name' => 'ТОО Клиент', 'budget' => $budget,
            'status' => 'active', 'deal_stage_id' => $wonStage->id,
            'responsible_user_id' => $this->manager->id, 'contract_date' => $contractDate,
        ]);
        $invoice = Invoice::create([
            'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id,
            'number' => 'INV-'.uniqid(), 'amount' => $budget, 'status' => 'paid',
            'date' => $contractDate,
        ]);
        Payment::create(['invoice_id' => $invoice->id, 'amount' => $budget, 'payment_date' => $contractDate]);

        return $deal;
    }

    private function giveDebt(float $amount, float $monthly): EmployeeDebt
    {
        $this->actingAs($this->financist)->withSession(['company_id' => $this->company->id])
            ->post(route('payroll.debts.store'), [
                'user_id' => $this->manager->id,
                'amount' => $amount, 'monthly_amount' => $monthly,
                'date' => now()->toDateString(), 'payment_method' => 'cash',
            ])->assertSessionHasNoErrors();

        return EmployeeDebt::latest('id')->firstOrFail();
    }

    public function test_issuing_debt_creates_confirmed_company_expense(): void
    {
        $debt = $this->giveDebt(300000, 50000);

        $this->assertEqualsWithDelta(300000, (float) $debt->amount, 0.01);
        $expense = Expense::findOrFail($debt->expense_id);
        $this->assertSame('confirmed', $expense->status);
        $this->assertSame('cash', $expense->payment_method);
        $this->assertEqualsWithDelta(300000, (float) $expense->amount, 0.01);
    }

    public function test_monthly_payment_is_capped_by_fixed_amount(): void
    {
        $month = now()->format('Y-m');
        $this->wonDeal(5_000_000, now()->startOfMonth()->toDateString());
        $debt = $this->giveDebt(300000, 50000);

        $bonus = app(\App\Services\PayrollService::class)->bonusByUserForMonth($month)[$this->manager->id] ?? 0;
        $this->assertGreaterThan(50000, $bonus, 'Бонус месяца должен превышать месячный платёж.');

        app(EmployeeDebtService::class)->chargeMonth($month);

        // Удержали ровно месячный платёж, а не весь бонус.
        $this->assertEqualsWithDelta(50000, $debt->fresh()->paidSum(), 0.01);
        $this->assertEqualsWithDelta(250000, $debt->fresh()->remaining(), 0.01);
    }

    public function test_no_bonus_means_no_deduction_and_debt_rolls_over(): void
    {
        $month = now()->format('Y-m');
        $debt = $this->giveDebt(300000, 50000); // сделок нет — бонуса нет

        app(EmployeeDebtService::class)->chargeMonth($month);

        // Оклад не тронут: долг как был, так и остался.
        $this->assertEqualsWithDelta(0, $debt->fresh()->paidSum(), 0.01);
        $this->assertEqualsWithDelta(300000, $debt->fresh()->remaining(), 0.01);
        $this->assertNull($debt->fresh()->closed_at);
    }

    public function test_small_bonus_pays_only_what_it_covers(): void
    {
        $month = now()->format('Y-m');
        // Маленькая сделка: бонус заведомо меньше месячного платежа.
        $this->wonDeal(120000, now()->startOfMonth()->toDateString());
        $debt = $this->giveDebt(300000, 50000);

        $bonus = (float) (app(\App\Services\PayrollService::class)->bonusByUserForMonth($month)[$this->manager->id] ?? 0);
        $this->assertLessThan(50000, $bonus);

        app(EmployeeDebtService::class)->chargeMonth($month);

        // Удержан весь бонус и ни тенге сверх него.
        $this->assertEqualsWithDelta($bonus, $debt->fresh()->paidSum(), 0.01);
    }

    public function test_charging_same_month_twice_does_not_double_deduct(): void
    {
        $month = now()->format('Y-m');
        $this->wonDeal(5_000_000, now()->startOfMonth()->toDateString());
        $debt = $this->giveDebt(300000, 50000);

        $service = app(EmployeeDebtService::class);
        $service->chargeMonth($month);
        $service->chargeMonth($month);

        $this->assertEqualsWithDelta(50000, $debt->fresh()->paidSum(), 0.01);
    }

    public function test_debt_closes_when_fully_repaid(): void
    {
        $month = now()->format('Y-m');
        $this->wonDeal(5_000_000, now()->startOfMonth()->toDateString());
        $debt = $this->giveDebt(40000, 40000); // закрывается за один месяц

        app(EmployeeDebtService::class)->chargeMonth($month);

        $debt->refresh();
        $this->assertEqualsWithDelta(0, $debt->remaining(), 0.01);
        $this->assertNotNull($debt->closed_at);
    }

    public function test_payroll_row_shows_plan_and_subtracts_it_from_payout(): void
    {
        $month = now()->format('Y-m');
        $this->wonDeal(5_000_000, now()->startOfMonth()->toDateString());
        $this->giveDebt(300000, 50000);

        $props = $this->actingAs($this->financist)->withSession(['company_id' => $this->company->id])
            ->get(route('payroll.index', ['month' => $month]))->assertOk()->viewData('page')['props'];

        $row = collect($props['rows'])->firstWhere('uid', $this->manager->id);
        $this->assertEqualsWithDelta(50000, $row['debt_charge'], 0.01);
        $this->assertEqualsWithDelta(300000, $row['debt_remaining'], 0.01);
        $this->assertEqualsWithDelta(250000, $row['debt_after'], 0.01);
        // К выплате уменьшено ровно на удержание и не тронуло оклад.
        $this->assertEqualsWithDelta($row['payout'] - 50000, $row['final'], 0.01);
        $this->assertEqualsWithDelta(300000, $row['base'], 0.01);
    }

    /**
     * Аванс и долг — разные механики и не мешают друг другу: аванс удерживается
     * целиком в своём месяце (из всей выплаты), долг — фиксированной суммой
     * из бонуса. Оба вместе не должны ни задваиваться, ни съедать друг друга.
     */
    public function test_advance_and_debt_are_independent(): void
    {
        $month = now()->format('Y-m');
        $this->wonDeal(5_000_000, now()->startOfMonth()->toDateString());
        $this->giveDebt(300000, 50000);

        $this->actingAs($this->financist)->withSession(['company_id' => $this->company->id])
            ->post(route('payroll.adjustments.store'), [
                'user_id' => $this->manager->id, 'type' => 'advance',
                'amount' => 70000, 'date' => now()->toDateString(), 'payment_method' => 'cash',
            ])->assertSessionHasNoErrors();

        $props = $this->actingAs($this->financist)->withSession(['company_id' => $this->company->id])
            ->get(route('payroll.index', ['month' => $month]))->assertOk()->viewData('page')['props'];

        $row = collect($props['rows'])->firstWhere('uid', $this->manager->id);
        $this->assertEqualsWithDelta(70000, $row['deductions'], 0.01, 'Аванс — разовое удержание месяца.');
        $this->assertEqualsWithDelta(50000, $row['debt_charge'], 0.01, 'Долг — свой фиксированный платёж.');
        $this->assertEqualsWithDelta($row['payout'] - 70000 - 50000, $row['final'], 0.01);
        // Аванс не гасит долг и наоборот.
        $this->assertEqualsWithDelta(300000, $row['debt_remaining'], 0.01);
    }

    public function test_manager_cannot_issue_debt(): void
    {
        $this->actingAs($this->manager)->post(route('payroll.debts.store'), [
            'user_id' => $this->manager->id, 'amount' => 100, 'monthly_amount' => 100,
            'date' => now()->toDateString(), 'payment_method' => 'cash',
        ])->assertForbidden();
    }

    public function test_monthly_payment_cannot_exceed_debt(): void
    {
        $this->actingAs($this->financist)->withSession(['company_id' => $this->company->id])
            ->post(route('payroll.debts.store'), [
                'user_id' => $this->manager->id, 'amount' => 10000, 'monthly_amount' => 50000,
                'date' => now()->toDateString(), 'payment_method' => 'cash',
            ])->assertSessionHasErrors('monthly_amount');
    }

    public function test_deleting_debt_removes_its_expense(): void
    {
        $debt = $this->giveDebt(300000, 50000);
        $expenseId = $debt->expense_id;

        $this->actingAs($this->financist)
            ->delete(route('payroll.debts.destroy', $debt->id))->assertRedirect();

        $this->assertNull(EmployeeDebt::find($debt->id));
        $this->assertSoftDeleted(Expense::withTrashed()->findOrFail($expenseId));
    }

    public function test_payroll_still_shows_the_charge_after_month_was_charged(): void
    {
        // Команда уже списала за месяц — ведомость обязана показывать удержание
        // и держать «К выплате» уменьшенным, иначе цифра прыгает после cron.
        $month = now()->format('Y-m');
        $this->wonDeal(5_000_000, now()->startOfMonth()->toDateString());
        $this->giveDebt(300000, 50000);

        app(EmployeeDebtService::class)->chargeMonth($month);

        $props = $this->actingAs($this->financist)->withSession(['company_id' => $this->company->id])
            ->get(route('payroll.index', ['month' => $month]))->assertOk()->viewData('page')['props'];

        $row = collect($props['rows'])->firstWhere('uid', $this->manager->id);
        $this->assertEqualsWithDelta(50000, $row['debt_charge'], 0.01);
        $this->assertEqualsWithDelta($row['payout'] - 50000, $row['final'], 0.01);
    }

    public function test_fully_repaid_debt_still_shows_its_charge_in_that_month(): void
    {
        // Долг закрылся этим месяцем — он обязан остаться в ведомости месяца
        // вместе со своим удержанием, иначе деньги списаны, а строки нет.
        $month = now()->format('Y-m');
        $this->wonDeal(5_000_000, now()->startOfMonth()->toDateString());
        $this->giveDebt(40000, 40000);

        app(EmployeeDebtService::class)->chargeMonth($month);

        $props = $this->actingAs($this->financist)->withSession(['company_id' => $this->company->id])
            ->get(route('payroll.index', ['month' => $month]))->assertOk()->viewData('page')['props'];

        $row = collect($props['rows'])->firstWhere('uid', $this->manager->id);
        $this->assertEqualsWithDelta(40000, $row['debt_charge'], 0.01);
        $this->assertEqualsWithDelta(40000, $row['debt_remaining'], 0.01, 'Долг на начало месяца.');
        $this->assertEqualsWithDelta(0, $row['debt_after'], 0.01);
        $this->assertEqualsWithDelta($row['payout'] - 40000, $row['final'], 0.01);
        $this->assertCount(1, $row['debts'], 'Закрытый долг остаётся в списке своего месяца.');
        $this->assertTrue($row['debts'][0]['closed']);
    }

    public function test_user_card_shows_the_same_debt_figures_as_payroll(): void
    {
        // Профиль сотрудника и ведомость обязаны показывать одно и то же —
        // иначе бухгалтер видит две разные правды.
        $month = now()->format('Y-m');
        $this->wonDeal(5_000_000, now()->startOfMonth()->toDateString());
        $this->giveDebt(300000, 50000);

        $card = $this->actingAs($this->financist)->withSession(['company_id' => $this->company->id])
            ->get(route('users.show', ['user' => $this->manager->id, 'month' => $month]))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame($month, $card['month']);
        $this->assertCount(1, $card['debts']);
        $this->assertEqualsWithDelta(50000, $card['debtPlan']['charge'], 0.01);
        $this->assertEqualsWithDelta(300000, $card['debtPlan']['before'], 0.01);
        $this->assertEqualsWithDelta(250000, $card['debtPlan']['after'], 0.01);

        $payrollRow = collect($this->actingAs($this->financist)->withSession(['company_id' => $this->company->id])
            ->get(route('payroll.index', ['month' => $month]))->viewData('page')['props']['rows'])
            ->firstWhere('uid', $this->manager->id);

        $this->assertEqualsWithDelta($payrollRow['debt_charge'], $card['debtPlan']['charge'], 0.01);
        $this->assertEqualsWithDelta($payrollRow['debt_remaining'], $card['debtPlan']['before'], 0.01);
    }

    public function test_user_card_month_filter_switches_adjustments_and_debt(): void
    {
        $this->giveDebt(300000, 50000);

        $this->actingAs($this->financist)->withSession(['company_id' => $this->company->id])
            ->post(route('payroll.adjustments.store'), [
                'user_id' => $this->manager->id, 'type' => 'fine',
                'amount' => 5000, 'date' => now()->toDateString(),
            ])->assertSessionHasNoErrors();

        $thisMonth = $this->actingAs($this->financist)->withSession(['company_id' => $this->company->id])
            ->get(route('users.show', ['user' => $this->manager->id, 'month' => now()->format('Y-m')]))
            ->viewData('page')['props'];
        $this->assertCount(1, $thisMonth['adjustments'], 'Штраф этого месяца виден.');

        $otherMonth = $this->actingAs($this->financist)->withSession(['company_id' => $this->company->id])
            ->get(route('users.show', ['user' => $this->manager->id, 'month' => now()->subMonthNoOverflow()->format('Y-m')]))
            ->viewData('page')['props'];
        $this->assertCount(0, $otherMonth['adjustments'], 'В другом месяце его быть не должно.');
        // Долг переходящий — он виден в любом месяце, пока не закрыт.
        $this->assertCount(1, $otherMonth['debts']);
    }

    public function test_manager_does_not_see_another_employee_card_money(): void
    {
        $other = User::factory()->create();
        $other->assignRole('manager');
        $other->companies()->attach($this->company->id);

        $this->actingAs($this->manager)
            ->get(route('users.show', $other->id))->assertForbidden();
    }

    public function test_employee_sees_only_own_debt_on_payroll_page(): void
    {
        // Сотрудник заходит на Зарплату и видит СВОЙ долг — и ничей больше.
        $other = User::factory()->create(['salary' => 200000]);
        $other->assignRole('manager');
        $other->companies()->attach($this->company->id);

        $this->giveDebt(300000, 50000);                       // долг Бахытжана
        $this->actingAs($this->financist)->withSession(['company_id' => $this->company->id])
            ->post(route('payroll.debts.store'), [
                'user_id' => $other->id, 'amount' => 700000, 'monthly_amount' => 70000,
                'date' => now()->toDateString(), 'payment_method' => 'bank',
            ])->assertSessionHasNoErrors();                   // долг коллеги

        $props = $this->actingAs($this->manager)->withSession(['company_id' => $this->company->id])
            ->get(route('payroll.index'))->assertOk()->viewData('page')['props'];

        $this->assertFalse($props['leadership']);
        $this->assertCount(1, $props['rows'], 'В ведомости только он сам.');
        $row = $props['rows'][0];
        $this->assertSame($this->manager->id, $row['uid']);
        $this->assertCount(1, $row['debts']);
        $this->assertEqualsWithDelta(300000, $row['debts'][0]['amount'], 0.01, 'Свой долг, не коллеги.');

        // Чужие суммы не должны просочиться в выдачу вообще.
        $this->assertStringNotContainsString('700000', json_encode($props['rows']));
    }
}
