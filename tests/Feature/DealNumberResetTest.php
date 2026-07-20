<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Services\DealNumberService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Нумерация сделок не учитывает удалённые: удалили сделку — её номер
 * освобождается; удалили все — нумерация начинается заново с 001.
 */
class DealNumberResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    private function makeDeal(?string $number = null): Deal
    {
        return Deal::create([
            'number' => $number ?? app(DealNumberService::class)->generate(),
            'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И', 'budget' => 1,
            'status' => 'active', 'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);
    }

    public function test_deleted_deals_do_not_advance_the_counter(): void
    {
        $svc = app(DealNumberService::class);

        $d1 = $this->makeDeal();
        $d2 = $this->makeDeal();
        $this->assertSame('BAIA-001', $d1->number);
        $this->assertSame('BAIA-002', $d2->number);

        // Удалили последнюю — номер освободился и выдаётся снова.
        $d2->delete();
        $this->assertSame('BAIA-002', $svc->generate());
        $this->assertStringContainsString('#del', $d2->fresh()->number);

        // Удалили все — нумерация начинается заново с 001.
        $d1->delete();
        $this->assertSame('BAIA-001', $svc->generate());
    }

    public function test_legacy_trashed_number_is_skipped_not_crashed(): void
    {
        // Удалённая сделка, чей номер НЕ переименован (легаси до миграции):
        // unique(number) занят — счётчик обязан перескочить, а не упасть.
        $legacy = $this->makeDeal('BAIA-002');
        $legacy->deleteQuietly(); // без событий: номер остаётся BAIA-002

        $this->makeDeal('BAIA-001');
        $this->assertSame('BAIA-003', app(DealNumberService::class)->generate());
    }
}
