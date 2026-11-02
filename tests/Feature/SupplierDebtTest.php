<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\SupplierDebt;
use App\Models\User;
use App\Services\FinanceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ТЗ 02.11.2026, этап 2: расход «деньгами / в долг», обязательный документ,
 * кредиторка поставщикам и погашение с чеком. Деньги не задваиваются: долг
 * сразу в затратах сделки, но кассу уменьшает только погашение.
 */
class SupplierDebtTest extends TestCase
{
    use RefreshDatabase;

    private Company $baia;

    private Deal $deal;

    private User $mgr;

    private User $fin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->baia = Company::where('code', 'BAIA')->firstOrFail();
        $stage = DealStage::create(['company_id' => $this->baia->id, 'name' => 'Закуп ЛДСП,МДФ', 'order' => 1, 'is_active' => true, 'stage_type' => 'shop_gate']);
        DealStage::create(['company_id' => $this->baia->id, 'name' => 'Логистика', 'order' => 2, 'is_active' => true, 'stage_type' => 'logistics']);
        $this->mgr = $this->user('manager');
        $this->fin = $this->user('financist');
        $this->deal = Deal::create(['number' => 'BAIA-777', 'name' => 'X', 'company_name' => 'ТОО Заказчик', 'client_name' => 'Шкаф',
            'budget' => 2000000, 'status' => 'active', 'company_id' => $this->baia->id, 'deal_stage_id' => $stage->id,
            'responsible_user_id' => $this->mgr->id]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->companies()->attach($this->baia->id);

        return $u;
    }

    private function debtExpense(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->mgr)->post(route('expenses.store'), array_merge([
            'expenseable_type' => 'deal', 'expenseable_id' => $this->deal->id, 'type' => 'purchase',
            'amount' => 300000, 'date' => now()->toDateString(), 'description' => 'ЛДСП 18 мм',
            'funding' => 'debt', 'supplier_name' => 'ТОО Лесторг', 'supplier_contact' => '+7 701 000 00 00',
            'debt_due_date' => now()->addDays(10)->toDateString(), 'file' => UploadedFile::fake()->image('nakladnaya.jpg'),
        ], $over));
    }

    private function cash(): float
    {
        $b = app(FinanceService::class)->companyBalances(null);

        return round($b['cash'] + $b['bank'], 2);
    }

    public function test_debt_expense_requires_supplier_contact_due_date_and_document(): void
    {
        $this->debtExpense(['supplier_name' => '', 'supplier_contact' => '', 'debt_due_date' => '', 'file' => null])
            ->assertSessionHasErrors(['supplier_name', 'supplier_contact', 'debt_due_date', 'file']);
        $this->assertSame(0, Expense::count());
    }

    public function test_debt_expense_counts_in_deal_but_not_in_cash_and_creates_debt(): void
    {
        $before = $this->cash();
        $this->debtExpense()->assertSessionHasNoErrors()->assertSessionHas('success');

        $e = Expense::firstOrFail();
        $this->assertSame('debt', $e->funding);
        $this->assertSame('confirmed', $e->status, 'бухгалтер не нужен — сразу в затратах сделки');
        $this->assertNull($e->payment_method, 'касса не тронута');
        $this->assertSame($before, $this->cash());

        $d = SupplierDebt::firstOrFail();
        $this->assertSame($this->deal->id, $d->deal_id);
        $this->assertSame($e->id, $d->expense_id);
        $this->assertSame(300000.0, (float) $d->amount);
        $this->assertSame('active', $d->state());
        $this->assertNotNull($d->document_path);

        // Гейт «Закупа» проходит без бухгалтера (п.3.4).
        $this->assertSame([], \App\Services\StageTransitionService::purchaseGateProblems($this->deal));
    }

    public function test_cash_expense_without_document_cannot_be_confirmed(): void
    {
        $this->actingAs($this->mgr)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $this->deal->id, 'type' => 'delivery',
            'amount' => 20000, 'date' => now()->toDateString(), 'description' => 'Доставка',
        ])->assertSessionHasNoErrors();
        $e = Expense::firstOrFail();

        $this->actingAs($this->fin)->patch(route('expenses.confirm', $e), ['payment_method' => 'cash', 'file' => UploadedFile::fake()->image('chek.jpg')])
            ->assertSessionHas('error');
        $this->assertSame('pending', $e->fresh()->status);

        // Менеджер прикрепил счёт — теперь бухгалтер подтверждает.
        $this->actingAs($this->mgr)->post(route('expenses.attachReceipt', $e), ['file' => UploadedFile::fake()->image('schet.jpg')])->assertSessionHas('success');
        $this->actingAs($this->fin)->patch(route('expenses.confirm', $e), ['payment_method' => 'cash', 'file' => UploadedFile::fake()->image('chek.jpg')])
            ->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $e->fresh()->status);
    }

    public function test_accountant_expense_without_document_is_only_a_draft(): void
    {
        $this->actingAs($this->fin)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $this->deal->id, 'type' => 'delivery',
            'amount' => 15000, 'date' => now()->toDateString(), 'payment_method' => 'cash',
        ])->assertSessionHas('success');
        $this->assertSame('pending', Expense::firstOrFail()->status);
    }

    public function test_repay_partially_then_fully_reduces_cash_once(): void
    {
        $this->debtExpense();
        $debt = SupplierDebt::firstOrFail();
        $before = $this->cash();

        // Не бухгалтер — нельзя; без чека — нельзя; больше остатка — нельзя.
        $this->actingAs($this->mgr)->post(route('supplierDebts.repay', $debt), ['amount' => 100, 'payment_method' => 'cash', 'date' => now()->toDateString(), 'file' => UploadedFile::fake()->image('c.jpg')])->assertForbidden();
        $this->actingAs($this->fin)->post(route('supplierDebts.repay', $debt), ['amount' => 100, 'payment_method' => 'cash', 'date' => now()->toDateString()])->assertSessionHasErrors('file');
        $this->actingAs($this->fin)->post(route('supplierDebts.repay', $debt), ['amount' => 999999, 'payment_method' => 'cash', 'date' => now()->toDateString(), 'file' => UploadedFile::fake()->image('c.jpg')])->assertSessionHasErrors('amount');

        $this->actingAs($this->fin)->post(route('supplierDebts.repay', $debt), ['amount' => 100000, 'payment_method' => 'bank', 'date' => now()->toDateString(), 'file' => UploadedFile::fake()->image('c.jpg')])->assertSessionHas('success');
        $this->assertSame(200000.0, $debt->fresh()->remaining());
        $this->assertSame(round($before - 100000, 2), $this->cash(), 'касса уменьшилась на погашение');

        $this->actingAs($this->fin)->post(route('supplierDebts.repay', $debt), ['amount' => 200000, 'payment_method' => 'cash', 'date' => now()->toDateString(), 'file' => UploadedFile::fake()->image('c.jpg')])->assertSessionHas('success');
        $this->assertSame('paid', $debt->fresh()->status);
        $this->assertSame(round($before - 300000, 2), $this->cash());

        // Затраты сделки — 300 000 один раз (погашения в них не добавляются).
        $this->assertSame(300000.0, (float) Expense::where('expenseable_id', $this->deal->id)->where('status', 'confirmed')->sum('amount'));
    }

    public function test_debt_with_payments_cannot_be_deleted_without_payments_can(): void
    {
        $admin = $this->user('admin');
        $this->debtExpense();
        $e = Expense::firstOrFail();
        $debt = SupplierDebt::firstOrFail();
        $this->actingAs($this->fin)->post(route('supplierDebts.repay', $debt), ['amount' => 1000, 'payment_method' => 'cash', 'date' => now()->toDateString(), 'file' => UploadedFile::fake()->image('c.jpg')]);

        $this->actingAs($admin)->delete(route('expenses.destroy', $e))->assertSessionHas('error');
        $this->assertModelExists($e);
    }

    public function test_debts_page_groups_by_deal_filters_and_is_leadership_only(): void
    {
        $this->debtExpense();
        $this->debtExpense(['amount' => 50000, 'description' => 'Кромка', 'supplier_name' => 'ИП Кромка', 'debt_due_date' => now()->subDay()->toDateString()]);

        $this->actingAs($this->mgr)->get(route('supplierDebts.index'))->assertForbidden();
        $this->actingAs($this->fin)->get(route('supplierDebts.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Finance/SupplierDebts')
                ->where('totals.owed', 350000)
                ->where('totals.overdue', 50000)
                ->has('groups', 1)
                ->where('groups.0.deal.number', 'BAIA-777')
                ->has('groups.0.rows', 2));
        $this->actingAs($this->fin)->get(route('supplierDebts.index', ['status' => 'overdue']))
            ->assertInertia(fn (Assert $p) => $p->where('groups.0.rows.0.supplier', 'ИП Кромка')->has('groups.0.rows', 1));
    }

    public function test_cash_book_shows_debt_repayment(): void
    {
        $this->debtExpense();
        $debt = SupplierDebt::firstOrFail();
        $this->actingAs($this->fin)->post(route('supplierDebts.repay', $debt), ['amount' => 5000, 'payment_method' => 'cash', 'date' => now()->toDateString(), 'file' => UploadedFile::fake()->image('c.jpg')]);

        $this->actingAs($this->fin)->get(route('cashBook.index', ['kind' => 'cash']))
            ->assertInertia(fn (Assert $p) => $p->where('operations', fn ($ops) => collect($ops)->contains(fn ($o) => $o['tag'] === 'Долг поставщику' && (float) $o['amount'] === 5000.0)));
    }
}
