<?php

namespace Tests\Feature;

use App\Models\AuditLog;
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
 * Аудит обязан отвечать на вопрос «что именно удалили»: при создании и
 * удалении пишется СНИМОК всей записи, а связь со сделкой находится и у
 * удалённых записей. Иначе в журнале остаётся лишь «что-то произошло с #116».
 */
class AuditSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $company = Company::where('code', 'BAIA')->firstOrFail();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->companies()->attach($company->id);

        $this->deal = Deal::create([
            'company_id' => $company->id, 'number' => 'BAIA-A-1', 'name' => 'Т', 'company_name' => 'ТОО',
            'budget' => 500000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);
    }

    private function makeExpense(): Expense
    {
        return Expense::create([
            'company_id' => $this->deal->company_id,
            'expenseable_type' => 'deal', 'expenseable_id' => $this->deal->id,
            'type' => 'delivery', 'amount' => 12345, 'date' => now()->toDateString(),
            'description' => 'Ошибочный расход', 'responsible_user_id' => $this->admin->id,
            'status' => 'confirmed', 'payment_method' => 'cash',
            'confirmed_by' => $this->admin->id, 'confirmed_at' => now(),
        ]);
    }

    public function test_delete_stores_full_snapshot_of_the_record(): void
    {
        $this->actingAs($this->admin);
        $expense = $this->makeExpense();
        $id = $expense->id;
        $expense->delete();

        $log = AuditLog::where('table_name', 'expenses')->where('record_id', $id)
            ->where('action', 'deleted')->firstOrFail();

        $this->assertSame(AuditLog::SNAPSHOT, $log->field_name);
        $snapshot = json_decode((string) $log->old_value, true);
        $this->assertIsArray($snapshot);
        // Сумма, описание и способ оплаты — то, ради чего журнал и ведётся.
        $this->assertEquals(12345, (float) $snapshot['amount']);
        $this->assertSame('Ошибочный расход', $snapshot['description']);
        $this->assertSame('cash', $snapshot['payment_method']);
        // Кириллица хранится читаемой, а не \uXXXX.
        $this->assertStringContainsString('Ошибочный расход', (string) $log->old_value);
    }

    public function test_create_also_stores_snapshot(): void
    {
        $this->actingAs($this->admin);
        $expense = $this->makeExpense();

        $log = AuditLog::where('table_name', 'expenses')->where('record_id', $expense->id)
            ->where('action', 'created')->firstOrFail();

        $this->assertSame(AuditLog::SNAPSHOT, $log->field_name);
        $this->assertEquals(12345, (float) json_decode((string) $log->new_value, true)['amount']);
    }

    public function test_audit_page_shows_what_was_deleted_and_keeps_the_deal_link(): void
    {
        $this->actingAs($this->admin);
        $expense = $this->makeExpense();
        $expense->delete();

        $props = $this->get(route('audit.index', ['table' => 'expenses']))
            ->assertOk()->viewData('page')['props'];

        $row = collect($props['logs']['data'])->firstWhere('action', 'deleted');
        $this->assertNotNull($row);
        // Связь со сделкой не теряется именно у удалённой записи.
        $this->assertSame($this->deal->number, $row['deal']['number']);

        $byLabel = collect($row['snapshot'])->pluck('value', 'label');
        $this->assertSame('12 345 ₸', $byLabel['Сумма']);
        $this->assertSame('Ошибочный расход', $byLabel['Описание']);
        $this->assertSame('Наличные', $byLabel['Способ оплаты']);
        $this->assertSame($this->admin->name, $byLabel['Кто подтвердил']);
        // Служебные поля в отчёт не лезут.
        $this->assertArrayNotHasKey('expenseable_type', $byLabel->all());
    }

    public function test_field_edit_still_logged_as_before(): void
    {
        $this->actingAs($this->admin);
        $expense = $this->makeExpense();
        $expense->update(['amount' => 999]);

        $log = AuditLog::where('table_name', 'expenses')->where('record_id', $expense->id)
            ->where('action', 'updated')->where('field_name', 'amount')->firstOrFail();

        $this->assertEquals(12345, (float) $log->old_value);
        $this->assertEquals(999, (float) $log->new_value);
    }
}
