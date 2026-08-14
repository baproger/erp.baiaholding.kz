<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Заявка «Расход компании» от сотрудника: он выставляет счёт бухгалтеру,
 * бухгалтер проверяет и оплачивает. До подтверждения деньги не уходят.
 */
class EmployeeExpenseRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private User $financist;

    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $company = Company::where('code', 'BAIA')->firstOrFail();
        foreach (['worker' => 'employee', 'financist' => 'financist'] as $prop => $role) {
            $u = User::factory()->create();
            $u->assignRole($role);
            $u->companies()->attach($company->id);
            $this->{$prop} = $u;
        }
        $this->category = ExpenseCategory::create(['name' => 'Бензин', 'is_active' => true]);
    }

    private function fileRequest(User $as, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as)->post(route('expenses.store'), array_merge([
            'category_id' => $this->category->id,
            'amount' => 15000,
            'date' => now()->toDateString(),
            'description' => 'Бензин на доставку',
            // Способ оплаты сотрудник прислать пытается — сервер его игнорирует.
            'payment_method' => 'cash',
        ], $extra));
    }

    public function test_worker_can_file_a_company_expense_request(): void
    {
        $this->fileRequest($this->worker)->assertSessionHasNoErrors();

        $e = Expense::firstOrFail();
        $this->assertSame('pending', $e->status, 'Заявка ждёт бухгалтера.');
        $this->assertSame($this->worker->id, $e->responsible_user_id);
        $this->assertNull($e->confirmed_by);
        // Откуда платить — решает бухгалтер, иначе касса уменьшилась бы заранее.
        $this->assertNull($e->payment_method);
    }

    public function test_worker_cannot_confirm_or_delete_own_request(): void
    {
        $this->fileRequest($this->worker)->assertSessionHasNoErrors();
        $e = Expense::firstOrFail();

        $this->actingAs($this->worker)
            ->patch(route('expenses.confirm', $e->id), ['payment_method' => 'cash'])->assertForbidden();
        $this->actingAs($this->worker)
            ->delete(route('expenses.destroy', $e->id))->assertForbidden();

        $this->assertSame('pending', $e->fresh()->status);
    }

    public function test_category_is_required_for_a_company_expense(): void
    {
        $this->fileRequest($this->worker, ['category_id' => ''])->assertSessionHasErrors('category_id');
    }

    public function test_worker_sees_only_own_requests_on_my_expenses(): void
    {
        $other = User::factory()->create();
        $other->assignRole('employee');
        $this->fileRequest($this->worker)->assertSessionHasNoErrors();
        $this->fileRequest($other, ['description' => 'Чужая заявка'])->assertSessionHasNoErrors();

        $props = $this->actingAs($this->worker)->get(route('myExpenses.index'))
            ->assertOk()->viewData('page')['props'];

        $descriptions = collect($props['mine'])->pluck('description');
        $this->assertTrue($descriptions->contains('Бензин на доставку'));
        $this->assertFalse($descriptions->contains('Чужая заявка'), 'Чужие заявки видеть нельзя.');
    }

    public function test_worker_cannot_open_the_accountant_board(): void
    {
        $this->actingAs($this->worker)->get(route('expenses.board'))->assertForbidden();
    }

    public function test_accountant_sees_the_request_and_confirming_pays_it(): void
    {
        $this->fileRequest($this->worker)->assertSessionHasNoErrors();
        $e = Expense::firstOrFail();

        $board = $this->actingAs($this->financist)->get(route('expenses.board'))
            ->assertOk()->viewData('page')['props'];
        $this->assertCount(1, $board['pending']);
        $this->assertSame($this->worker->name, $board['pending'][0]['author']['name']);

        $this->actingAs($this->financist)->patch(route('expenses.confirm', $e->id), [
            'payment_method' => 'cash',
            'file' => \Illuminate\Http\UploadedFile::fake()->image('чек.jpg'),
        ])->assertSessionHasNoErrors();

        $e->refresh();
        $this->assertSame('confirmed', $e->status);
        $this->assertSame('cash', $e->payment_method);
        $this->assertSame($this->financist->id, (int) $e->confirmed_by);
    }
}
