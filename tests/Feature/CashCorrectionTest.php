<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\FinanceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Корректировка кассы (инвентаризация): финансист задаёт фактический остаток
 * наличных; разница хранится в Setting и не трогает платежи/расходы/отчёты.
 */
class CashCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_financist_sets_actual_cash_and_manager_cannot(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $fin = User::factory()->create();
        $fin->assignRole('financist');
        $mgr = User::factory()->create();
        $mgr->assignRole('manager');

        // Обнуление кассы: фактический остаток 0.
        $this->actingAs($fin)->post(route('finance.cashCorrection'), ['actual' => 0])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(0.0, app(FinanceService::class)->companyBalances(null)['cash']);

        // Повторная корректировка: фактический 500 000 — касса ровно 500 000.
        $this->actingAs($fin)->post(route('finance.cashCorrection'), ['actual' => 500000])
            ->assertRedirect();
        $this->assertSame(500000.0, app(FinanceService::class)->companyBalances(null)['cash']);
        $this->assertNotNull(Setting::get('cash_correction'));

        // Менеджер кассу не корректирует.
        $this->actingAs($mgr)->post(route('finance.cashCorrection'), ['actual' => 1])->assertForbidden();
    }
}
