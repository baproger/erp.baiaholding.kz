<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use App\Support\ReportCache;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Кеш отчётов: версия сдвигается изменением денег, без изменений — не сдвигается. */
class ReportCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_version_bumps_on_money_model_change_only(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $baia = Company::where('code', 'BAIA')->firstOrFail();
        $mgr = User::factory()->create();
        $mgr->assignRole('manager');

        $v0 = ReportCache::version();
        $deal = Deal::create([
            'company_id' => $baia->id, 'number' => 'BAIA-001', 'name' => 'Сделка', 'company_name' => 'ТОО',
            'budget' => 100000, 'status' => 'active', 'deal_stage_id' => DealStage::orderBy('order')->first()->id,
            'responsible_user_id' => $mgr->id,
        ]);
        $v1 = ReportCache::version();
        $this->assertNotSame($v0, $v1, 'создание сделки должно сдвинуть версию');

        // Ничего не менялось — версия та же.
        $this->assertSame($v1, ReportCache::version());

        usleep(2000);
        $deal->update(['budget' => 200000]);
        $this->assertNotSame($v1, ReportCache::version(), 'изменение суммы должно сдвинуть версию');
    }

    public function test_report_is_served_from_cache_until_bump(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $baia = Company::where('code', 'BAIA')->firstOrFail();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->companies()->attach($baia->id);

        $calls = 0;
        $request = \Illuminate\Http\Request::create('/reports/deals', 'GET', ['from' => '2026-01-01']);
        $request->setUserResolver(fn () => $admin);
        $build = function () use (&$calls) { $calls++; return ['n' => $calls]; };

        $this->assertSame(['n' => 1], ReportCache::remember($request, 'test', $build));
        $this->assertSame(['n' => 1], ReportCache::remember($request, 'test', $build), 'повтор — из кеша');
        usleep(2000);
        ReportCache::bump();
        $this->assertSame(['n' => 2], ReportCache::remember($request, 'test', $build), 'после bump — пересчёт');
    }
}
