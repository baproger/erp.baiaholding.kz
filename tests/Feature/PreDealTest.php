<?php

namespace Tests\Feature;

use App\Models\PreDeal;
use App\Models\PreDealChecklistItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Предварительные сделки: расчёт как в Excel, порог маржи 15%, персонализация. */
class PreDealTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    public function test_margin_calculated_like_excel(): void
    {
        // Строка из Excel: 633333 − закуп 270000 − партнёр 10% (63333.30)
        // − доставка 100000 − налог 3% (18999.99) = 180999.71 → маржа 28.58%.
        $mgr = $this->user('manager');
        $this->actingAs($mgr)->post(route('preDeals.store'), [
            'product' => 'Стеллаж', 'contract_sum' => 633333, 'purchase_price' => 270000,
            'partner_pct' => 10, 'delivery' => 100000, 'commission' => 0,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $p = PreDeal::firstOrFail();
        $this->assertEquals(63333.30, (float) $p->partner_sum);
        $this->assertEquals(18999.99, (float) $p->tax);
        $this->assertEquals(180999.71, (float) $p->remainder);
        $this->assertEquals(28.58, (float) $p->margin);
    }

    public function test_low_margin_rejected_high_margin_confirmed(): void
    {
        $mgr = $this->user('manager');
        // Маржа 9.42% (шкаф из Excel) — подтверждение отклоняется.
        $this->actingAs($mgr)->post(route('preDeals.store'), [
            'product' => 'Шкаф', 'contract_sum' => 3225306, 'purchase_price' => 1897552.14,
            'partner_pct' => 5, 'delivery' => 766000,
        ]);
        $low = PreDeal::firstOrFail();
        $this->assertEquals(9.42, (float) $low->margin);
        $this->actingAs($mgr)->post(route('preDeals.confirm', $low->id))->assertSessionHas('error');
        $this->assertSame('new', $low->fresh()->status);

        // Маржа 44.89% (парта) — подтверждается, создаётся сделка.
        $this->actingAs($mgr)->post(route('preDeals.store'), [
            'product' => 'Парта младший класс', 'customer' => 'ГУ Школа №5', 'contract_sum' => 1600000,
            'purchase_price' => 700000, 'partner_pct' => 5, 'commission' => 53760,
        ]);
        $ok = PreDeal::where('product', 'Парта младший класс')->firstOrFail();
        $this->assertEquals(44.89, (float) $ok->margin);
        $this->actingAs($mgr)->post(route('preDeals.confirm', $ok->id))->assertSessionHas('success');
        $ok->refresh();
        $this->assertSame('confirmed', $ok->status);
        $this->assertNotNull($ok->deal_id);
        $this->assertSame('ГУ Школа №5', $ok->deal->company_name);
        $this->assertEquals(1600000, (float) $ok->deal->budget);
        $this->assertSame($mgr->id, $ok->deal->responsible_user_id);
    }

    public function test_manager_sees_only_own_lots(): void
    {
        $a = $this->user('manager');
        $b = $this->user('manager');
        PreDeal::create(PreDeal::calculate(['product' => 'A', 'contract_sum' => 100]) + ['user_id' => $a->id]);
        PreDeal::create(PreDeal::calculate(['product' => 'B', 'contract_sum' => 100]) + ['user_id' => $b->id]);

        $this->actingAs($a)->get(route('preDeals.index'))
            ->assertInertia(fn (Assert $p) => $p->has('preDeals', 1)->where('preDeals.0.product', 'A'));
        // Руководство видит все + рейтинг.
        $this->actingAs($this->user('financist'))->get(route('preDeals.index'))
            ->assertInertia(fn (Assert $p) => $p->has('preDeals', 2)->has('stats'));
    }

    public function test_checklist_managed_by_admin_or_financist_only(): void
    {
        $fin = $this->user('financist');
        $mgr = $this->user('manager');

        $this->actingAs($fin)->post(route('preDealItems.store'), ['label' => 'Выставил счёт'])->assertRedirect();
        $item = PreDealChecklistItem::where('label', 'Выставил счёт')->firstOrFail();
        $this->actingAs($mgr)->post(route('preDealItems.store'), ['label' => 'X'])->assertForbidden();

        // Менеджер ставит галочку на СВОЁМ лоте.
        PreDeal::create(PreDeal::calculate(['product' => 'A', 'contract_sum' => 100]) + ['user_id' => $mgr->id]);
        $lot = PreDeal::firstOrFail();
        $this->actingAs($mgr)->post(route('preDeals.check', [$lot->id, $item->id]))->assertRedirect();
        $this->assertTrue((bool) ($lot->fresh()->checks[(string) $item->id] ?? false));
    }
}
