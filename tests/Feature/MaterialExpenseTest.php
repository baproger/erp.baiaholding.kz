<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\Material;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialExpenseTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Deal $deal;

    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $company = Company::where('code', 'BAIA')->firstOrFail();
        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->companies()->attach($company->id);

        $this->deal = Deal::create([
            'company_id' => $company->id, 'number' => 'BAIA-T-1', 'name' => 'Т', 'company_name' => 'ТОО',
            'client_name' => 'Стол', 'budget' => 100000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
            'responsible_user_id' => $this->manager->id,
        ]);

        $this->material = Material::create(['company_id' => $company->id, 'name' => 'ЛДСП', 'unit' => 'штук', 'quantity' => 20]);
    }

    /**
     * Склад есть и у ASU (правило от 13.10.2026): менеджер ASU списывает ткани
     * в свою сделку по необходимости, бухгалтер подтверждает — остаток ASU
     * уменьшается; материал BAIA в сделку ASU списать нельзя.
     */
    public function test_asu_manager_writes_off_own_warehouse_and_accountant_confirms(): void
    {
        $asu = Company::where('code', 'ASU')->firstOrFail();
        $mgr = User::factory()->create();
        $mgr->assignRole('manager');
        $mgr->companies()->attach($asu->id);
        $fin = User::factory()->create();
        $fin->assignRole('financist');
        $fin->companies()->attach($asu->id);
        $deal = Deal::create([
            'company_id' => $asu->id, 'number' => 'ASU-T-1', 'name' => 'Т', 'company_name' => 'ТОО',
            'client_name' => 'Шторы', 'budget' => 100000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id, 'responsible_user_id' => $mgr->id,
        ]);
        $fabric = Material::create(['company_id' => $asu->id, 'name' => 'Ткань блэкаут', 'unit' => 'м', 'quantity' => 50, 'price' => 2000]);

        $this->actingAs($mgr)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'material_id' => $fabric->id, 'qty' => 10, 'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
        $expense = Expense::where('expenseable_id', $deal->id)->firstOrFail();
        $this->assertSame('pending', $expense->status);
        $this->assertEquals(20000.0, (float) $expense->amount, '10 м × 2000');
        $this->assertEquals(50.0, (float) $fabric->fresh()->quantity, 'до подтверждения склад не трогаем');

        $this->actingAs($fin)->patch(route('expenses.confirm', $expense))->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $expense->fresh()->status);
        $this->assertEquals(40.0, (float) $fabric->fresh()->quantity);

        // Материал BAIA в сделку ASU — отказ.
        $this->actingAs($mgr)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'material_id' => $this->material->id, 'qty' => 1, 'amount' => 100, 'date' => now()->toDateString(),
        ])->assertSessionHasErrors('material_id');
    }

    public function test_manager_material_expense_is_pending_until_accountant_confirms(): void
    {
        // Правило от 18.08.2026: списание менеджера ждёт бухгалтера,
        // склад до подтверждения НЕ трогается.
        $this->actingAs($this->manager)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $this->deal->id,
            'material_id' => $this->material->id, 'qty' => 5,
            'amount' => 50000, 'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $expense = Expense::first();
        $this->assertSame('pending', $expense->status);
        $this->assertEquals(20.0, (float) $this->material->fresh()->quantity);
        $this->assertStringContainsString('ЛДСП', $expense->description);

        // Бухгалтер подтверждает — без чека и способа оплаты; склад списывается.
        $financist = User::factory()->create();
        $financist->assignRole('financist');
        $financist->companies()->attach($this->deal->company_id);

        $this->actingAs($financist)->patch(route('expenses.confirm', $expense->id))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('confirmed', $expense->fresh()->status);
        $this->assertEquals(15.0, (float) $this->material->fresh()->quantity);
    }

    public function test_accountant_material_expense_writes_off_stock_immediately(): void
    {
        $financist = User::factory()->create();
        $financist->assignRole('financist');
        $financist->companies()->attach($this->deal->company_id);

        $this->actingAs($financist)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $this->deal->id,
            'material_id' => $this->material->id, 'qty' => 5,
            'amount' => 50000, 'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('confirmed', Expense::first()->status);
        $this->assertEquals(15.0, (float) $this->material->fresh()->quantity);
    }

    public function test_material_expense_rejected_when_stock_insufficient(): void
    {
        $this->actingAs($this->manager)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $this->deal->id,
            'material_id' => $this->material->id, 'qty' => 999,
            'amount' => 1, 'date' => now()->toDateString(),
        ])->assertSessionHasErrors('qty');

        $this->assertEquals(20.0, (float) $this->material->fresh()->quantity);
        $this->assertSame(0, Expense::count());
    }

    public function test_deleting_material_expense_restores_stock_only_after_confirmation(): void
    {
        $financist = User::factory()->create();
        $financist->assignRole('financist');
        $financist->companies()->attach($this->deal->company_id);

        // Pending-списание склад не трогало — удаление ничего не возвращает.
        $this->actingAs($this->manager)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $this->deal->id,
            'material_id' => $this->material->id, 'qty' => 8,
            'amount' => 10000, 'date' => now()->toDateString(),
        ]);
        $this->assertEquals(20.0, (float) $this->material->fresh()->quantity);
        $this->actingAs($financist)->delete(route('expenses.destroy', Expense::first()->id))->assertRedirect();
        $this->assertEquals(20.0, (float) $this->material->fresh()->quantity);

        // Подтверждённое списание: склад списан, удаление возвращает остаток.
        $this->actingAs($financist)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $this->deal->id,
            'material_id' => $this->material->id, 'qty' => 8,
            'amount' => 10000, 'date' => now()->toDateString(),
        ]);
        $this->assertEquals(12.0, (float) $this->material->fresh()->quantity);
        $this->actingAs($financist)->delete(route('expenses.destroy', Expense::first()->id))->assertRedirect();
        $this->assertEquals(20.0, (float) $this->material->fresh()->quantity);
    }

    public function test_material_expense_amount_is_qty_times_price(): void
    {
        // У материала есть закупочная цена — сумма считается на сервере,
        // присланная вручную сумма игнорируется.
        $this->material->update(['price' => 1200]);

        $this->actingAs($this->manager)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $this->deal->id,
            'material_id' => $this->material->id, 'qty' => 5,
            'amount' => 1, 'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertEquals(6000.0, (float) Expense::first()->amount); // 5 × 1200
    }

    public function test_material_expense_without_price_keeps_manual_amount(): void
    {
        // Легаси-позиция без цены: сумму вводят вручную, как раньше.
        $this->actingAs($this->manager)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $this->deal->id,
            'material_id' => $this->material->id, 'qty' => 5,
            'amount' => 7000, 'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertEquals(7000.0, (float) Expense::first()->amount);
    }

    public function test_material_expense_without_price_and_amount_is_rejected(): void
    {
        // Материал без цены + без суммы: раньше сервер молча создавал
        // подтверждённый расход на 0 ₸ со списанием склада.
        $this->actingAs($this->manager)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $this->deal->id,
            'material_id' => $this->material->id, 'qty' => 5,
            'date' => now()->toDateString(),
        ])->assertSessionHasErrors('amount');

        $this->assertEquals(20.0, (float) $this->material->fresh()->quantity);
        $this->assertSame(0, Expense::count());
    }

    public function test_editing_derived_material_expense_amount_returns_error(): void
    {
        // Производная сумма (кол-во × цена) не правится: честная ошибка
        // вместо молчаливого игнора с «Расход обновлён».
        $this->material->update(['price' => 1200]);
        $this->actingAs($this->manager)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $this->deal->id,
            'material_id' => $this->material->id, 'qty' => 5,
            'date' => now()->toDateString(),
        ]);
        $expense = Expense::first();
        $this->assertEquals(6000.0, (float) $expense->amount);

        $this->actingAs($this->manager)->put(route('expenses.update', $expense->id), [
            'amount' => 9999, 'date' => now()->toDateString(),
        ])->assertSessionHasErrors('amount');
        $this->assertEquals(6000.0, (float) $expense->fresh()->amount);
    }

    public function test_other_expense_by_manager_goes_pending(): void
    {
        // Прочий расход менеджера ждёт подтверждения бухгалтера (этап 4).
        $this->actingAs($this->manager)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $this->deal->id,
            'amount' => 5000, 'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('pending', Expense::first()->status);
    }
}
