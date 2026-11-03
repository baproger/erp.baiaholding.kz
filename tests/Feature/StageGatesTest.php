<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\PreDeal;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * ТЗ 02.11.2026, этап 1: гейты этапов работают во ВСЕХ путях — «Далее»,
 * перетаскивание (deals.stage), «Отправить в цех», возврат из цеха.
 *  1) без ответственного дальше нельзя;
 *  2) с «Дизайн и расчет» — только со сметой; галочку без сметы не поставить;
 *  4) с «Закупа» — расход «Закуп» есть и КАЖДЫЙ расход подтверждён;
 *  обход «в цех → Готово» закрыт; перескочить этап с проверкой нельзя;
 *  админ переоткрывает закрытую сделку только с причиной (в Историю).
 */
class StageGatesTest extends TestCase
{
    use RefreshDatabase;

    private array $st = [];

    private Company $baia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->baia = Company::where('code', 'BAIA')->firstOrFail();
        $i = 1;
        foreach ([
            'contract' => ['Заключение договора', null, null],
            'design' => ['Дизайн и расчет', 'Подтвердить дизайн и расчет', 'designer'],
            'shop_gate' => ['Закуп ЛДСП,МДФ', 'Подтвердить закуп ЛДСП, МДФ', 'supplier'],
            'logistics' => ['Логистика', null, null],
            'assembly' => ['Сборка', null, null],
            'act' => ['Акт утверждение', null, null],
            'esf' => ['ЭСФ', null, null],
            'payment_won' => ['Тендер закрыт', null, null],
        ] as $type => [$name, $gate, $role]) {
            $this->st[$type] = DealStage::create(['company_id' => $this->baia->id, 'name' => $name, 'order' => $i++, 'is_active' => true,
                'stage_type' => $type, 'is_won' => $type === 'payment_won', 'gate_task_title' => $gate, 'gate_task_role' => $role, 'gate_task_days' => $gate ? 2 : null]);
        }
        ProjectStage::create(['company_id' => $this->baia->id, 'name' => 'Кесу', 'order' => 1, 'is_active' => true, 'workshop' => 'Ағаш цех']);
        ProjectStage::create(['company_id' => $this->baia->id, 'name' => 'Отправка', 'order' => 2, 'is_active' => true, 'workshop' => 'Ағаш цех', 'is_completed' => true]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->companies()->attach($this->baia->id);

        return $u;
    }

    private function deal(string $stage, ?User $responsible = null): Deal
    {
        return Deal::create(['number' => 'BAIA-'.uniqid(), 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'Шкаф',
            'bin' => 'Д-77', 'contract_date' => '2026-11-01', 'lot_number' => '3', 'unit' => 'штук',
            'budget' => 1000000, 'status' => 'active', 'company_id' => $this->baia->id,
            'deal_stage_id' => $this->st[$stage]->id, 'responsible_user_id' => $responsible?->id]);
    }

    private function expense(Deal $deal, string $type, string $status, float $amount = 50000): Expense
    {
        return Expense::create(['expenseable_type' => 'deal', 'expenseable_id' => $deal->id, 'amount' => $amount,
            'date' => now()->toDateString(), 'status' => $status, 'type' => $type, 'description' => $type,
            'file_path' => 'receipts/schet.jpg']);
    }

    // ---- п.1 Ответственный ----

    public function test_no_responsible_blocks_every_forward_path(): void
    {
        $admin = $this->user('admin');
        $deal = $this->deal('contract');

        $this->actingAs($admin)->patch(route('deals.advance', $deal))->assertSessionHas('error');
        $this->assertStringContainsString('ответственного', session('error'));
        $this->actingAs($admin)->patch(route('deals.stage', $deal), ['deal_stage_id' => $this->st['design']->id])->assertSessionHas('error');
        $this->assertSame($this->st['contract']->id, $deal->fresh()->deal_stage_id);

        $deal->update(['responsible_user_id' => $this->user('manager')->id]);
        $this->actingAs($admin)->patch(route('deals.advance', $deal))->assertSessionHas('success');
        $this->assertSame($this->st['design']->id, $deal->fresh()->deal_stage_id);
    }

    public function test_deal_from_lot_gets_lot_author_as_responsible(): void
    {
        $mgr = $this->user('manager');
        $this->actingAs($mgr)->withSession(['company_id' => $this->baia->id])->post(route('preDeals.store'), [
            'product' => 'Шкаф', 'customer' => 'ТОО Клиент', 'contract_sum' => 1600000, 'purchase_price' => 700000,
        ]);
        $lot = PreDeal::firstOrFail();
        $this->actingAs($mgr)->post(route('preDeals.confirm', $lot->id))->assertSessionHas('success');

        $deal = $lot->fresh()->deal;
        $this->assertNotNull($deal);
        $this->assertSame($mgr->id, $deal->responsible_user_id);
    }

    public function test_fill_responsible_command_dry_run_then_real(): void
    {
        $mgr = $this->user('manager');
        $deal = $this->deal('contract');
        AuditLog::create(['user_id' => $mgr->id, 'table_name' => 'deals', 'record_id' => $deal->id, 'action' => 'created', 'created_at' => now()]);

        $this->artisan('deals:fill-responsible', ['--dry-run' => true])->expectsOutputToContain('будет проставлен')->assertSuccessful();
        $this->assertNull($deal->fresh()->responsible_user_id, 'dry-run ничего не меняет');

        $this->artisan('deals:fill-responsible')->assertSuccessful();
        $this->assertSame($mgr->id, $deal->fresh()->responsible_user_id);
    }

    /** Без номера, даты договора и количества с «Заключение договора» не уйти. */
    public function test_contract_stage_requires_number_date_and_quantity(): void
    {
        $admin = $this->user('admin');
        $deal = $this->deal('contract', $this->user('manager'));
        $deal->update(['bin' => null, 'contract_date' => null, 'lot_number' => null]);

        $this->actingAs($admin)->patch(route('deals.advance', $deal))->assertSessionHas('error');
        $this->assertStringContainsString('номер договора, дату договора, количество', session('error'));
        $this->assertSame($this->st['contract']->id, $deal->fresh()->deal_stage_id);

        $deal->update(['bin' => '№ 15/26', 'contract_date' => '2026-11-02', 'lot_number' => '4']);
        $this->actingAs($admin)->patch(route('deals.advance', $deal))->assertSessionHas('success');
        $this->assertSame($this->st['design']->id, $deal->fresh()->deal_stage_id);
    }

    // ---- п.2 Смета ----

    public function test_design_stage_requires_estimate_and_gate_checkbox_too(): void
    {
        $mgr = $this->user('manager');
        $designer = $this->user('designer');
        $admin = $this->user('admin');
        $deal = $this->deal('contract', $mgr);
        $this->actingAs($admin)->patch(route('deals.advance', $deal))->assertSessionHas('success'); // → Дизайн (гейт-задача дизайнеру)

        // Галочку без сметы не поставить — ни из карточки, ни со страницы задач.
        $this->actingAs($designer)->patch(route('deals.stageTask', $deal))->assertSessionHas('error');
        $this->assertStringContainsString('смету', session('error'));
        $task = Task::where('taskable_id', $deal->id)->where('title', 'like', 'Подтвердить дизайн%')->firstOrFail();
        $this->actingAs($designer)->patch(route('tasks.status', $task), ['status' => 'done'])->assertSessionHas('error');
        $this->assertNotSame('done', $task->fresh()->status);

        // Дизайнер загружает смету просто файлом — сумму он не считает (03.11.2026).
        $this->actingAs($designer)->post(route('documents.store'), ['documentable_type' => 'deal', 'documentable_id' => $deal->id,
            'kind' => 'estimate', 'file' => UploadedFile::fake()->create('smeta.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();

        $this->actingAs($designer)->patch(route('deals.stageTask', $deal))->assertSessionHas('success');
        $this->actingAs($admin)->patch(route('deals.advance', $deal))->assertSessionHas('success');
        $this->assertSame($this->st['shop_gate']->id, $deal->fresh()->deal_stage_id);
    }

    public function test_only_designer_or_admin_uploads_estimate(): void
    {
        $mgr = $this->user('manager');
        $deal = $this->deal('design', $mgr);
        $this->actingAs($mgr)->post(route('documents.store'), ['documentable_type' => 'deal', 'documentable_id' => $deal->id,
            'kind' => 'estimate', 'amount' => 100, 'file' => UploadedFile::fake()->create('s.pdf', 5, 'application/pdf')])->assertForbidden();
    }

    // ---- Перескок этапов ----

    public function test_cannot_skip_a_stage_with_checks_by_dragging(): void
    {
        $admin = $this->user('admin');
        $deal = $this->deal('contract', $this->user('manager'));

        $this->actingAs($admin)->patch(route('deals.stage', $deal), ['deal_stage_id' => $this->st['logistics']->id])->assertSessionHas('error');
        $this->assertStringContainsString('перескочить', session('error'));
        $this->assertSame($this->st['contract']->id, $deal->fresh()->deal_stage_id);
    }

    // ---- п.4 Закуп ----

    public function test_purchase_stage_requires_purchase_and_every_expense_confirmed(): void
    {
        $admin = $this->user('admin');
        $deal = $this->deal('shop_gate', $this->user('manager'));

        // Нет расхода «Закуп».
        $this->actingAs($admin)->patch(route('deals.advance', $deal))->assertSessionHas('error');
        $this->assertStringContainsString('Нет расхода «Закуп»', session('error'));

        // Закуп есть, но фурнитура не подтверждена — ошибка называет конкретный расход.
        $this->expense($deal, 'purchase', 'confirmed');
        $fit = $this->expense($deal, 'fittings', 'pending', 193750);
        $this->actingAs($admin)->patch(route('deals.advance', $deal))->assertSessionHas('error');
        $this->assertStringContainsString('Расход #'.$fit->id.' Фурнитура 193 750 ₸ — ждёт подтверждения бухгалтера', session('error'));
        $this->assertSame($this->st['shop_gate']->id, $deal->fresh()->deal_stage_id);

        $fit->update(['status' => 'confirmed']);
        $this->expense($deal, 'fittings', 'confirmed'); // для гейта Логистики (Закуп+Фурнитура)
        $this->actingAs($admin)->patch(route('deals.advance', $deal))->assertSessionHas('success');
        $this->assertSame($this->st['logistics']->id, $deal->fresh()->deal_stage_id);
    }

    // ---- Обход через цех ----

    public function test_send_to_workshop_only_from_purchase_and_only_when_gate_passes(): void
    {
        $mgr = $this->user('manager');
        $deal = $this->deal('contract', $mgr);

        // С «Договора» в цех нельзя (раньше сервер это пропускал).
        $this->actingAs($mgr)->post(route('deals.toWorkshop', $deal), ['workshop' => 'Ағаш цех'])->assertSessionHas('error');
        $this->assertSame('active', $deal->fresh()->status);

        // На «Закупе» с неподтверждённым расходом — тоже нельзя.
        $deal->update(['deal_stage_id' => $this->st['shop_gate']->id]);
        $this->expense($deal, 'purchase', 'pending');
        $this->actingAs($mgr)->post(route('deals.toWorkshop', $deal), ['workshop' => 'Ағаш цех'])->assertSessionHas('error');
        $this->assertNull(Project::where('deal_id', $deal->id)->first());

        Expense::where('expenseable_id', $deal->id)->update(['status' => 'confirmed']);
        $this->actingAs($mgr)->post(route('deals.toWorkshop', $deal), ['workshop' => 'Ағаш цех'])->assertSessionHas('success');
        $this->assertSame('closed', $deal->fresh()->status);
    }

    public function test_return_from_workshop_rechecks_purchase_gate(): void
    {
        $mgr = $this->user('manager');
        $deal = $this->deal('shop_gate', $mgr);
        $this->expense($deal, 'purchase', 'confirmed');
        $this->expense($deal, 'fittings', 'confirmed');
        $this->actingAs($mgr)->post(route('deals.toWorkshop', $deal), ['workshop' => 'Ағаш цех'])->assertSessionHas('success');
        $project = Project::where('deal_id', $deal->id)->firstOrFail();

        // Пока заказ в цехе, добавили неподтверждённый расход — «Готово» не вернёт сделку.
        $this->expense($deal, 'delivery', 'pending');
        [$ok, $msg] = app(\App\Services\ProjectService::class)->completeAndReturnDeal($project->fresh());
        $this->assertFalse($ok);
        $this->assertStringContainsString('ждёт подтверждения бухгалтера', $msg);
        $this->assertSame('closed', $deal->fresh()->status);

        Expense::where('expenseable_id', $deal->id)->update(['status' => 'confirmed']);
        [$ok2] = app(\App\Services\ProjectService::class)->completeAndReturnDeal($project->fresh());
        $this->assertTrue($ok2);
        $this->assertSame($this->st['logistics']->id, $deal->fresh()->deal_stage_id);
    }

    /** Нет ни одного снабженца — гейт «Закупа» не выключается: задача без исполнителя, галочку ставит админ. */
    public function test_gate_holds_even_when_nobody_has_the_role(): void
    {
        $admin = $this->user('admin');
        $deal = $this->deal('design', $this->user('manager'));
        $this->giveEstimate($deal);
        $this->user('designer');
        app(\App\Services\StageTransitionService::class)->createGateTask($deal, $this->st['design']);
        Task::where('taskable_id', $deal->id)->update(['status' => 'done']);
        $this->actingAs($admin)->patch(route('deals.advance', $deal))->assertSessionHas('success'); // → Закуп, снабженцев нет

        $this->assertSame(1, Task::where('taskable_id', $deal->id)->where('title', 'like', 'Подтвердить закуп%')->whereNull('assignee_id')->count());
        $this->expense($deal, 'purchase', 'confirmed');
        $this->actingAs($admin)->post(route('deals.toWorkshop', $deal), ['workshop' => 'Ағаш цех'])->assertSessionHas('error');
        $this->assertStringContainsString('Подтвердить закуп', session('error'));

        $this->actingAs($admin)->patch(route('deals.stageTask', $deal))->assertSessionHas('success');
        $this->actingAs($admin)->post(route('deals.toWorkshop', $deal), ['workshop' => 'Ағаш цех'])->assertSessionHas('success');
    }

    // ---- Переоткрытие ----

    public function test_reopen_is_admin_only_with_reason_and_logged(): void
    {
        $mgr = $this->user('manager');
        $admin = $this->user('admin');
        $deal = $this->deal('shop_gate', $mgr);
        $deal->update(['status' => 'closed', 'closed_at' => now()]);

        $this->actingAs($mgr)->patch(route('deals.reopen', $deal), ['reason' => 'нужно доработать'])->assertForbidden();
        $this->actingAs($admin)->patch(route('deals.reopen', $deal), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->patch(route('deals.reopen', $deal), ['reason' => 'Клиент изменил размеры'])->assertSessionHas('success');

        $fresh = $deal->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame($this->st['shop_gate']->id, $fresh->deal_stage_id, 'этап не меняется');
        $this->assertDatabaseHas('audit_logs', ['table_name' => 'deals', 'record_id' => $deal->id, 'action' => 'reopened', 'new_value' => 'Клиент изменил размеры']);
    }
}
