<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\WorkshopScreen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Далее» с ТВ-экрана цеха: двигает заказ на следующий этап по коду экрана
 * в сессии; чужой цех и чужая сессия — 403.
 */
class ScreenAdvanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Таймеры табло считает сервер (09.10.2026): у ТВ сбиты часы → было «0м».
     * Экран получает готовые секунды «в цехе / на этапе» и «сейчас» сервера.
     */
    public function test_screen_sends_server_computed_timers(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-10-09 12:00:00');
        $stage = ProjectStage::create(['name' => 'Упаковка', 'order' => 1, 'is_active' => true, 'workshop' => 'Ағаш цех']);
        \Illuminate\Support\Carbon::setTestNow('2026-10-01 18:00:00'); // отправлен в цех 7д 18ч назад
        $project = Project::create(['number' => 'PRJ-9', 'name' => 'Мебель', 'workshop' => 'Ағаш цех', 'project_stage_id' => $stage->id, 'status' => 'active']);
        \App\Models\ProjectStageLog::where('project_id', $project->id)->whereNull('left_at')
            ->update(['entered_at' => '2026-10-07 12:00:00']); // на этапе ровно 2 суток
        \Illuminate\Support\Carbon::setTestNow('2026-10-09 12:00:00');

        $screen = WorkshopScreen::create(['workshop' => 'Ағаш цех', 'kind' => 'workshop', 'code' => '111222', 'is_active' => true]);

        $this->withSession(['workshop_screen_id' => $screen->id, 'workshop_screen_code' => '111222'])
            ->get(route('screen.show'))
            ->assertInertia(fn ($page) => $page->component('Screen/Workshop')
                ->where('serverNow', now()->getTimestampMs())
                ->where('tzOffset', now()->getOffset())
                ->where('projects.0.in_workshop_seconds', 7 * 86400 + 18 * 3600)
                ->where('projects.0.on_stage_seconds', 2 * 86400));
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_screen_advances_own_workshop_order_only(): void
    {
        $s1 = ProjectStage::create(['name' => 'Кесу', 'order' => 1, 'is_active' => true, 'workshop' => 'Металл цех']);
        $s2 = ProjectStage::create(['name' => 'Тесу', 'order' => 2, 'is_active' => true, 'workshop' => 'Металл цех']);
        $agashStage = ProjectStage::create(['name' => 'Кесу', 'order' => 1, 'is_active' => true, 'workshop' => 'Ағаш цех']);

        $metal = Project::create(['number' => 'PRJ-1', 'name' => 'Стол', 'workshop' => 'Металл цех', 'project_stage_id' => $s1->id, 'status' => 'active']);
        $agash = Project::create(['number' => 'PRJ-2', 'name' => 'Шкаф', 'workshop' => 'Ағаш цех', 'project_stage_id' => $agashStage->id, 'status' => 'active']);

        $screen = WorkshopScreen::create(['workshop' => 'Металл цех', 'kind' => 'workshop', 'code' => '123456', 'is_active' => true]);
        $session = ['workshop_screen_id' => $screen->id, 'workshop_screen_code' => '123456'];

        // Свой цех — этап двигается.
        $this->withSession($session)->post(route('screen.advanceProject', $metal->id))->assertRedirect();
        $this->assertSame($s2->id, $metal->fresh()->project_stage_id);

        // Последний этап — дальше нельзя (Готово только в системе).
        $this->withSession($session)->post(route('screen.advanceProject', $metal->id))->assertSessionHas('error');
        $this->assertSame($s2->id, $metal->fresh()->project_stage_id);

        // Чужой цех — 403.
        $this->withSession($session)->post(route('screen.advanceProject', $agash->id))->assertForbidden();

        // Без кода экрана (нет сессии) — 403.
        $this->flushSession();
        $this->post(route('screen.advanceProject', $metal->id))->assertForbidden();
    }

    public function test_screen_completes_order_only_from_last_stage(): void
    {
        $s1 = ProjectStage::create(['name' => 'Кесу', 'order' => 1, 'is_active' => true, 'workshop' => 'Металл цех']);
        $s2 = ProjectStage::create(['name' => 'Отправка', 'order' => 2, 'is_active' => true, 'workshop' => 'Металл цех']);
        $dealStage = \App\Models\DealStage::create(['name' => 'Закуп', 'order' => 1, 'is_active' => true]);
        $logistics = \App\Models\DealStage::create(['name' => 'Логистика', 'order' => 2, 'is_active' => true, 'stage_type' => 'logistics']);
        $deal = \App\Models\Deal::create(['number' => 'T-1', 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И', 'budget' => 100, 'status' => 'closed', 'deal_stage_id' => $dealStage->id]);
        $project = Project::create(['number' => 'PRJ-3', 'name' => 'Стол', 'deal_id' => $deal->id, 'workshop' => 'Металл цех', 'project_stage_id' => $s1->id, 'status' => 'active']);
        // Правило 28.09.2026: Металл цех — Закуп + Фурнитура + материал со склада, иначе «Готово» не пройдёт.
        $material = \App\Models\Material::create(['name' => 'Труба', 'unit' => 'штук', 'quantity' => 10, 'price' => 100]);
        foreach ([['purchase', null], ['fittings', null], ['direct', $material->id]] as [$t, $mid]) {
            \App\Models\Expense::create(['expenseable_type' => 'deal', 'expenseable_id' => $deal->id, 'amount' => 100,
                'date' => now()->toDateString(), 'status' => 'pending', 'type' => $t, 'material_id' => $mid, 'description' => $t]);
        }

        $screen = WorkshopScreen::create(['workshop' => 'Металл цех', 'kind' => 'workshop', 'code' => '654321', 'is_active' => true]);
        $session = ['workshop_screen_id' => $screen->id, 'workshop_screen_code' => '654321'];

        // Не последний этап — «Готово» недоступно.
        $this->withSession($session)->post(route('screen.completeProject', $project->id))->assertForbidden();

        // С «Отправки» — заказ завершён, сделка вернулась на «Логистику».
        $project->update(['project_stage_id' => $s2->id]);
        $this->withSession($session)->post(route('screen.completeProject', $project->id))->assertRedirect();
        $this->assertSame('completed', $project->fresh()->status);
        $this->assertSame($logistics->id, $deal->fresh()->deal_stage_id);
        $this->assertSame('active', $deal->fresh()->status);
    }
}
