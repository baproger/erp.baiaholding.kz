<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Правила от 16.09.2026:
 *  1) на «Логистику» — только с обязательными расходами (хватает заявки): Закуп + Фурнитура,
 *     у заказа в «Металл цех» ещё и «По материалам (со склада)» (правило 28.09.2026);
 *  2) с «Логистики» дальше — только после галочки завсклада (роль supplier).
 */
class LogisticsGateTest extends TestCase
{
    use RefreshDatabase;

    private DealStage $assembly;

    private DealStage $logistics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        // Полная воронка (без act/esf/won позиционные фолбэки ломают переходы).
        DealStage::create(['name' => 'Договор', 'order' => 1, 'is_active' => true, 'stage_type' => 'contract']);
        $this->assembly = DealStage::create(['name' => 'Сборка', 'order' => 2, 'is_active' => true, 'stage_type' => 'assembly']);
        $this->logistics = DealStage::create(['name' => 'Логистика', 'order' => 3, 'is_active' => true, 'stage_type' => 'logistics',
            'gate_task_title' => 'Подтвердить получение товара на складе', 'gate_task_role' => 'supplier', 'gate_task_days' => 2]);
        DealStage::create(['name' => 'Акт утверждение', 'order' => 4, 'is_active' => true, 'stage_type' => 'act']);
        DealStage::create(['name' => 'ЭСФ', 'order' => 5, 'is_active' => true, 'stage_type' => 'esf']);
        DealStage::create(['name' => 'Оплата успешно', 'order' => 6, 'is_active' => true, 'stage_type' => 'payment_won', 'is_won' => true]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->companies()->attach(Company::where('code', 'BAIA')->firstOrFail()->id);

        return $u;
    }

    private function deal(User $owner, ?string $companyCode = 'BAIA'): Deal
    {
        return Deal::create(['number' => 'T-'.uniqid(), 'name' => 'X', 'company_name' => 'ТОО', 'budget' => 100000,
            'company_id' => $companyCode ? Company::where('code', $companyCode)->firstOrFail()->id : null,
            'status' => 'active', 'deal_stage_id' => $this->assembly->id, 'responsible_user_id' => $owner->id]);
    }

    private function expense(Deal $deal, string $type, ?int $materialId = null): void
    {
        Expense::create(['expenseable_type' => 'deal', 'expenseable_id' => $deal->id, 'amount' => 1000,
            'date' => now()->toDateString(), 'status' => 'pending', 'type' => $type, 'material_id' => $materialId,
            'responsible_user_id' => $deal->responsible_user_id, 'description' => $type]);
    }

    /** Базовый набор для Ағаш цеха / ASU / сделки без заказа: Закуп + Фурнитура. */
    private function baseExpenses(Deal $deal): void
    {
        $this->expense($deal, 'purchase');
        $this->expense($deal, 'fittings');
    }

    private function projectIn(Deal $deal, string $workshop): void
    {
        \App\Models\Project::create(['number' => 'P-'.uniqid(), 'name' => 'Заказ', 'deal_id' => $deal->id,
            'workshop' => $workshop, 'status' => 'active']);
    }

    private function toLogistics(User $u, Deal $deal)
    {
        return $this->actingAs($u)->patch(route('deals.stage', $deal->id), ['deal_stage_id' => $this->logistics->id]);
    }

    public function test_blocked_without_purchase_and_fittings(): void
    {
        $mgr = $this->user('manager');
        $deal = $this->deal($mgr);
        $this->expense($deal, 'delivery'); // доставка не в счёт

        $this->toLogistics($mgr, $deal)->assertSessionHas('error');
        $this->assertSame($this->assembly->id, $deal->fresh()->deal_stage_id);
        // В сообщении — чего именно не хватает.
        $this->assertStringContainsString('Закуп', session('error'));
        $this->assertStringContainsString('Фурнитура', session('error'));
        $this->assertStringNotContainsString('со склада', session('error'), 'без металл-цеха склад не требуется');
    }

    public function test_wood_workshop_needs_only_purchase_and_fittings(): void
    {
        $mgr = $this->user('manager');
        $deal = $this->deal($mgr);
        $this->projectIn($deal, 'Ағаш цех');
        $this->baseExpenses($deal);

        $this->toLogistics($mgr, $deal)->assertSessionHas('success');
        $this->assertSame($this->logistics->id, $deal->fresh()->deal_stage_id);
    }

    public function test_metal_workshop_also_needs_warehouse_material(): void
    {
        $mgr = $this->user('manager');
        $deal = $this->deal($mgr);
        $this->projectIn($deal, 'Металл цех');
        $this->baseExpenses($deal);

        $this->toLogistics($mgr, $deal)->assertSessionHas('error');
        $this->assertStringContainsString('По материалам (со склада)', session('error'));
        $this->assertSame($this->assembly->id, $deal->fresh()->deal_stage_id);

        $material = \App\Models\Material::create(['company_id' => $deal->company_id, 'name' => 'Труба', 'unit' => 'штук', 'quantity' => 10, 'price' => 100]);
        $this->expense($deal, 'direct', $material->id);

        $this->toLogistics($mgr, $deal)->assertSessionHas('success');
        $this->assertSame($this->logistics->id, $deal->fresh()->deal_stage_id);
    }

    public function test_legacy_sheet_counts_as_purchase_and_metal_as_warehouse(): void
    {
        $mgr = $this->user('manager');
        $deal = $this->deal($mgr);
        $this->projectIn($deal, 'Металл цех');
        $this->expense($deal, 'sheet');    // «Лист» = закуп
        $this->expense($deal, 'metal');    // старый «Металл» из цеха = материал
        $this->expense($deal, 'fittings');

        $this->toLogistics($mgr, $deal)->assertSessionHas('success');
    }

    public function test_passes_with_pending_expenses_and_gate_task_goes_to_supplier(): void
    {
        $supplier = $this->user('supplier');
        $mgr = $this->user('manager');
        $deal = $this->deal($mgr);
        $this->baseExpenses($deal);

        $this->toLogistics($mgr, $deal)->assertSessionHas('success');
        $this->assertSame($this->logistics->id, $deal->fresh()->deal_stage_id);

        // Гейт-задача — снабженцу.
        $task = Task::where('title', 'like', 'Подтвердить получение товара на складе%')->firstOrFail();
        $this->assertSame($supplier->id, $task->assignee_id);
    }

    public function test_workshop_complete_blocked_without_expenses(): void
    {
        $mgr = $this->user('manager');
        $deal = $this->deal($mgr);
        $project = app(ProjectService::class)->createFromDeal($deal);

        [$ok, $message] = app(ProjectService::class)->completeAndReturnDeal($project);
        $this->assertFalse($ok);
        $this->assertStringContainsString('Закуп', $message);

        $this->baseExpenses($deal);
        [$ok2] = app(ProjectService::class)->completeAndReturnDeal($project->fresh());
        $this->assertTrue($ok2);
        $this->assertSame($this->logistics->id, $deal->fresh()->deal_stage_id);
    }

    public function test_exit_logistics_only_after_supplier_confirms(): void
    {
        $supplier = $this->user('supplier');
        $mgr = $this->user('manager');
        $deal = $this->deal($mgr);
        $this->baseExpenses($deal);
        $this->actingAs($mgr);
        app(StageTransitionService::class)->moveToStage($deal, $this->logistics);

        // До галочки — стоп.
        $this->actingAs($mgr)->patch(route('deals.advance', $deal->id))->assertSessionHas('error');
        $this->assertSame($this->logistics->id, $deal->fresh()->deal_stage_id);

        // Менеджер галочку поставить не может, снабженец — может.
        $this->actingAs($mgr)->patch(route('deals.stageTask', $deal->id))->assertForbidden();
        $this->actingAs($supplier)->patch(route('deals.stageTask', $deal->id))->assertSessionHas('success');

        // После галочки сделка идёт дальше.
        $this->actingAs($mgr)->patch(route('deals.advance', $deal->id))->assertSessionHas('success');
        $this->assertNotSame($this->logistics->id, $deal->fresh()->deal_stage_id);
    }

    /** ASU (цехов нет): Закуп + Фурнитура — как Ағаш цех. */
    public function test_asu_deal_requires_purchase_and_fittings(): void
    {
        $mgr = $this->user('manager');
        $mgr->companies()->attach(Company::where('code', 'ASU')->firstOrFail()->id);
        $deal = $this->deal($mgr, 'ASU');

        $this->toLogistics($mgr, $deal)->assertSessionHas('error');
        $this->assertSame($this->assembly->id, $deal->fresh()->deal_stage_id);

        $this->baseExpenses($deal);
        $this->toLogistics($mgr, $deal)->assertSessionHas('success');
        $this->assertSame($this->logistics->id, $deal->fresh()->deal_stage_id);
    }
}
