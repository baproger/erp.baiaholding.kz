<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\PayrollAdjustment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Аванс сотруднику (корректировка ЗП) автоматически фиксируется в Расходах
 * на Финансах: подтверждённый расход категории «Расходы по сотрудникам».
 */
class AdvanceExpenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_advance_creates_confirmed_expense_and_deletes_with_adjustment(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $fin = User::factory()->create();
        $fin->assignRole('financist');
        $emp = User::factory()->create(['name' => 'Иван Цех']);
        $emp->assignRole('employee');

        // Аванс 100 000 наличными.
        $this->actingAs($fin)->post(route('payroll.adjustments.store'), [
            'user_id' => $emp->id, 'type' => 'advance', 'amount' => 100000,
            'date' => '2026-07-24', 'note' => 'на бензин', 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $adj = PayrollAdjustment::first();
        $expense = Expense::find($adj->expense_id);
        $this->assertNotNull($expense);
        $this->assertSame('confirmed', $expense->status);
        $this->assertSame('cash', $expense->payment_method);
        $this->assertSame(100000.0, (float) $expense->amount);
        $this->assertStringContainsString('Иван Цех', $expense->description);
        $this->assertSame('Расходы по сотрудникам', \App\Models\ExpenseCategory::find($expense->category_id)->name);

        // Прочие типы корректировок расход НЕ создают.
        $this->actingAs($fin)->post(route('payroll.adjustments.store'), [
            'user_id' => $emp->id, 'type' => 'fine', 'amount' => 5000, 'date' => '2026-07-24',
        ])->assertRedirect();
        $this->assertSame(1, Expense::count());

        // Удаление — только админ (06.11.2026): бухгалтер получает 403.
        $this->actingAs($fin)->delete(route('payroll.adjustments.destroy', $adj->id))->assertForbidden();
        $this->assertNotNull(Expense::find($expense->id));

        // Админ удалил аванс — расход тоже удалён (деньги «вернулись»).
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->delete(route('payroll.adjustments.destroy', $adj->id))->assertRedirect();
        $this->assertNull(Expense::find($expense->id));
        $this->assertNull(PayrollAdjustment::find($adj->id));
    }

    /**
     * Обратная синхронизация: выплату бонуса удалили со стороны кассы
     * (Финансы/Касса → расход) — корректировка в ЗП/Бонусах уходит сама,
     * иначе «выплачено» остаётся, а деньги в кассу уже вернулись.
     */
    public function test_deleting_payout_expense_removes_payroll_adjustment(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $emp = User::factory()->create(['name' => 'Нурбол']);
        $emp->assignRole('manager');

        $this->actingAs($admin)->post(route('payroll.adjustments.store'), [
            'user_id' => $emp->id, 'type' => 'payout', 'source' => 'bonus', 'amount' => 4444444,
            'date' => '2026-11-03', 'note' => 'тест', 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $adj = PayrollAdjustment::firstOrFail();
        $this->assertSame('bonus_payout', Expense::findOrFail($adj->expense_id)->employee_payout);

        $this->actingAs($admin)->delete(route('expenses.destroy', $adj->expense_id))->assertRedirect();

        $this->assertSoftDeleted('expenses', ['id' => $adj->expense_id]);
        $this->assertDatabaseMissing('payroll_adjustments', ['id' => $adj->id]);
    }

    /** Долг сотруднику: удалили расход из кассы — долг (и его погашения) уходит. */
    public function test_deleting_debt_expense_removes_employee_debt(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $emp = User::factory()->create();
        $emp->assignRole('manager');

        $this->actingAs($admin)->post(route('payroll.debts.store'), [
            'user_id' => $emp->id, 'amount' => 300000, 'monthly_amount' => 50000,
            'date' => '2026-11-03', 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $debt = \App\Models\EmployeeDebt::firstOrFail();
        $this->actingAs($admin)->delete(route('expenses.destroy', $debt->expense_id))->assertRedirect();

        $this->assertDatabaseMissing('employee_debts', ['id' => $debt->id]);
    }
}
