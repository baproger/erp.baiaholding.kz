<?php

namespace Tests\Feature;

use App\Models\CashReceipt;
use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Кассовая книга: остаток на начало дня переносится с конца предыдущего,
 * каждая операция двигает баланс, конец дня = начало + приход − расход.
 */
class CashBookTest extends TestCase
{
    use RefreshDatabase;

    private User $financist;

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
    }

    private function receipt(float $amount, string $date, string $method = 'cash'): void
    {
        CashReceipt::create([
            'company_id' => $this->company->id, 'amount' => $amount, 'method' => $method,
            'source' => 'Взнос учредителя', 'date' => $date, 'created_by' => $this->financist->id,
        ]);
    }

    private function spend(float $amount, string $date, string $method = 'cash', string $status = 'confirmed'): void
    {
        $deal = Deal::firstOr(fn () => Deal::create([
            'company_id' => $this->company->id, 'number' => 'BAIA-C-1', 'name' => 'Т', 'company_name' => 'ТОО',
            'budget' => 100000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]));

        Expense::create([
            'company_id' => $this->company->id, 'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'type' => 'delivery', 'amount' => $amount, 'date' => $date, 'description' => 'Доставка',
            'status' => $status, 'payment_method' => $method,
            'confirmed_by' => $status === 'confirmed' ? $this->financist->id : null,
        ]);
    }

    private function book(string $date, string $kind = 'cash'): array
    {
        return $this->actingAs($this->financist)->withSession(['company_id' => $this->company->id])
            ->get(route('cashBook.index', ['date' => $date, 'kind' => $kind]))
            ->assertOk()->viewData('page')['props'];
    }

    public function test_closing_of_one_day_becomes_opening_of_the_next(): void
    {
        $this->receipt(2_000_000, '2026-08-03');
        $this->spend(1_406_280, '2026-08-03');

        $day = $this->book('2026-08-03');
        $this->assertEqualsWithDelta(0, $day['opening'], 0.01, 'Первый день начинается с нуля.');
        $this->assertEqualsWithDelta(2_000_000, $day['income'], 0.01);
        $this->assertEqualsWithDelta(1_406_280, $day['expense'], 0.01);
        $this->assertEqualsWithDelta(593_720, $day['closing'], 0.01);

        // Следующий день открывается ровно тем, чем закрылся предыдущий.
        $next = $this->book('2026-08-04');
        $this->assertEqualsWithDelta(593_720, $next['opening'], 0.01);
    }

    public function test_each_operation_carries_running_balance(): void
    {
        $this->receipt(1_000_000, '2026-08-03');
        $this->spend(200_000, '2026-08-03');
        $this->spend(300_000, '2026-08-03');

        $ops = collect($this->book('2026-08-03')['operations']);

        $this->assertCount(3, $ops);
        // Баланс каждой строки = предыдущий ± сумма операции.
        $running = 0.0;
        foreach ($ops as $op) {
            $running += $op['kind'] === 'in' ? $op['amount'] : -$op['amount'];
            $this->assertEqualsWithDelta($running, $op['balance'], 0.01);
        }
        $this->assertEqualsWithDelta(500_000, $ops->last()['balance'], 0.01);
    }

    public function test_only_cash_and_only_confirmed_expenses_count(): void
    {
        $this->receipt(500_000, '2026-08-03', 'bank');   // банк — мимо кассы
        $this->spend(100_000, '2026-08-03', 'bank');     // банк — мимо кассы
        $this->spend(70_000, '2026-08-03', 'cash', 'pending'); // ждёт бухгалтера

        $day = $this->book('2026-08-03');

        $this->assertEqualsWithDelta(0, $day['income'], 0.01);
        $this->assertEqualsWithDelta(0, $day['expense'], 0.01);
        $this->assertCount(0, $day['operations']);
    }

    public function test_manager_cannot_open_the_cash_book(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $manager->companies()->attach($this->company->id);

        $this->actingAs($manager)->get(route('cashBook.index'))->assertForbidden();
    }

    /** Банковская книга — свой поток: наличные в неё не лезут и наоборот. */
    public function test_bank_book_shows_bank_operations_only(): void
    {
        $this->receipt(500_000, '2026-08-03', 'bank');
        $this->spend(80_000, '2026-08-03', 'bank');
        $this->receipt(100_000, '2026-08-03', 'cash');

        $bank = $this->book('2026-08-03', 'bank');
        $this->assertSame('bank', $bank['kind']);
        $this->assertEqualsWithDelta(500_000, $bank['income'], 0.01);
        $this->assertEqualsWithDelta(80_000, $bank['expense'], 0.01);
        $this->assertEqualsWithDelta(420_000, $bank['closing'], 0.01);
        $this->assertCount(2, $bank['operations']);

        // Касса при этом видит только свои 100 000.
        $cash = $this->book('2026-08-03');
        $this->assertEqualsWithDelta(100_000, $cash['income'], 0.01);
        $this->assertEqualsWithDelta(0, $cash['expense'], 0.01);
    }

    /** Расход входит в книгу ТОЛЬКО после подтверждения бухгалтером. */
    public function test_expense_enters_the_book_only_after_confirmation(): void
    {
        $this->spend(80_000, '2026-08-03', 'bank', 'pending');
        $this->assertEqualsWithDelta(0, $this->book('2026-08-03', 'bank')['expense'], 0.01);

        Expense::latest('id')->firstOrFail()->update([
            'status' => 'confirmed', 'confirmed_by' => $this->financist->id, 'confirmed_at' => now(),
        ]);

        $this->assertEqualsWithDelta(80_000, $this->book('2026-08-03', 'bank')['expense'], 0.01);
    }
}
