<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ТЗ 02.11.2026, п.5: «Срок» по договору не перезаписывается; продление —
 * «Доп. срок» с причиной, не дальше 60 дней от срока; просрочка, канбан,
 * «Просроченные» — по эффективному сроку; каждое продление — в Истории.
 */
class DealExtensionTest extends TestCase
{
    use RefreshDatabase;

    private Company $baia;

    private User $mgr;

    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 10:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->baia = Company::where('code', 'BAIA')->firstOrFail();
        $stage = DealStage::create(['company_id' => $this->baia->id, 'name' => 'Сборка', 'order' => 1, 'is_active' => true, 'stage_type' => 'assembly']);
        DealStage::create(['company_id' => $this->baia->id, 'name' => 'Акт утверждение', 'order' => 2, 'is_active' => true, 'stage_type' => 'act']);
        $this->mgr = User::factory()->create();
        $this->mgr->assignRole('manager');
        $this->mgr->companies()->attach($this->baia->id);
        $this->deal = Deal::create(['number' => 'BAIA-500', 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'Парта', 'address' => 'Туркестан',
            'budget' => 100000, 'status' => 'active', 'company_id' => $this->baia->id, 'deal_stage_id' => $stage->id,
            'responsible_user_id' => $this->mgr->id, 'deadline' => '2026-11-01']); // уже просрочен
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function extend(array $data)
    {
        return $this->actingAs($this->mgr)->patch(route('deals.extend', $this->deal), $data);
    }

    public function test_reason_required_and_60_day_cap_from_contract_deadline(): void
    {
        $this->extend(['extended_deadline' => '2026-11-15'])->assertSessionHasErrors('extension_reason');
        $this->extend(['extended_deadline' => '2027-01-01', 'extension_reason' => 'Задержка поставки'])->assertSessionHasErrors('extended_deadline'); // > 01.11 + 60 = 31.12
        $this->extend(['extended_deadline' => '2026-10-30', 'extension_reason' => 'Задержка поставки'])->assertSessionHasErrors('extended_deadline'); // раньше срока
        $this->assertNull($this->deal->fresh()->extended_deadline);

        $this->extend(['extended_deadline' => '2026-12-31', 'extension_reason' => 'Задержка поставки'])->assertSessionHasNoErrors()->assertSessionHas('success');
        $fresh = $this->deal->fresh();
        $this->assertSame('2026-11-01', $fresh->deadline->toDateString(), 'исходный срок не меняется');
        $this->assertSame('2026-12-31', $fresh->extended_deadline->toDateString());
        $this->assertSame(1, $fresh->extensions_count);
    }

    public function test_extension_removes_overdue_everywhere_and_is_logged(): void
    {
        $this->actingAs($this->mgr)->get(route('deals.overdue'))
            ->assertInertia(fn (Assert $p) => $p->where('deals', fn ($d) => collect($d)->contains('id', $this->deal->id)));

        $this->extend(['extended_deadline' => '2026-11-15', 'extension_reason' => 'Заказчик перенёс приёмку']);

        $this->actingAs($this->mgr)->get(route('deals.overdue'))
            ->assertInertia(fn (Assert $p) => $p->where('deals', fn ($d) => ! collect($d)->contains('id', $this->deal->id)));
        $this->assertSame(0, Deal::overdueBy(today())->count());
        $this->assertSame('2026-11-15', $this->deal->fresh()->effective_deadline);

        $this->assertDatabaseHas('audit_logs', ['table_name' => 'deals', 'record_id' => $this->deal->id, 'field_name' => 'extended_deadline']);
        $this->assertDatabaseHas('audit_logs', ['table_name' => 'deals', 'record_id' => $this->deal->id, 'field_name' => 'extension_reason', 'new_value' => 'Заказчик перенёс приёмку']);

        // Второе продление — счётчик растёт (аналитика сопровождения).
        $this->extend(['extended_deadline' => '2026-11-30', 'extension_reason' => 'Вторая задержка']);
        $this->assertSame(2, $this->deal->fresh()->extensions_count);
    }

    public function test_contract_deadline_cannot_be_rewritten_by_manager_admin_can(): void
    {
        $payload = ['company_name' => 'ТОО', 'client_name' => 'Парта', 'address' => 'Туркестан', 'budget' => 100000, 'deadline' => '2026-12-20'];
        $this->actingAs($this->mgr)->put(route('deals.update', $this->deal), $payload)->assertSessionHasErrors('deadline');
        $this->assertSame('2026-11-01', $this->deal->fresh()->deadline->toDateString());

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->put(route('deals.update', $this->deal), $payload)->assertSessionHasNoErrors();
        $this->assertSame('2026-12-20', $this->deal->fresh()->deadline->toDateString());
    }

    public function test_active_workshop_order_follows_extended_deadline(): void
    {
        $project = Project::create(['number' => 'P-1', 'name' => 'Заказ', 'deal_id' => $this->deal->id, 'status' => 'active', 'deadline' => '2026-11-01']);
        $this->extend(['extended_deadline' => '2026-11-20', 'extension_reason' => 'Задержка цеха']);
        $this->assertSame('2026-11-20', $project->fresh()->deadline->toDateString());
    }

    public function test_kanban_date_filter_uses_effective_deadline(): void
    {
        $this->extend(['extended_deadline' => '2026-11-20', 'extension_reason' => 'Задержка цеха']);
        $this->actingAs($this->mgr)->get(route('deals.index', ['date_from' => '2026-11-19', 'date_to' => '2026-11-21']))
            ->assertInertia(fn (Assert $p) => $p->where('deals', fn ($d) => collect($d)->contains('id', $this->deal->id)));
    }
}
