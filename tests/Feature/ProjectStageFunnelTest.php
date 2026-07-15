<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ProjectStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectStageFunnelTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_stages_do_not_duplicate_with_legacy_common_ones(): void
    {
        $baia = Company::firstOrCreate(['code' => 'BAIA'], ['name' => 'BAIA', 'is_active' => true]);
        $asu = Company::firstOrCreate(['code' => 'ASU'], ['name' => 'ASU', 'is_active' => true]);

        // Легаси «общие» этапы (company_id = null) с теми же названиями, что и у фирмы.
        foreach (['Кесу', 'Упаковка'] as $i => $name) {
            ProjectStage::create(['name' => $name, 'order' => $i + 1, 'is_active' => true]);
            ProjectStage::create(['name' => $name, 'order' => $i + 1, 'is_active' => true, 'company_id' => $baia->id]);
        }

        // У BAIA есть СВОИ этапы → только они, без легаси-дублей (Кесу+Кесу).
        $names = ProjectStage::funnel($baia->id)->pluck('name');
        $this->assertCount(2, $names);
        $this->assertSame($names->count(), $names->unique()->count());

        // У ASU своих нет → фолбэк на общие (легаси), а не пусто.
        $this->assertCount(2, ProjectStage::funnel($asu->id));

        // Без компании («Все») — как раньше, всё вместе.
        $this->assertCount(4, ProjectStage::funnel(null));
    }
}
