<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Переключение фирмы возвращает на ТУ ЖЕ страницу И с теми же параметрами:
 * без query фильтр (месяц, период, поиск) молча слетал на значения по
 * умолчанию, и это выглядело как «данные пропали, видно только после F5».
 */
class CompanySwitchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('admin');
        $u->companies()->sync(Company::where('is_active', true)->pluck('id')->all());

        return $u;
    }

    public function test_switch_keeps_page_query_parameters(): void
    {
        $admin = $this->admin();
        $asu = Company::where('code', 'ASU')->firstOrFail();

        $this->actingAs($admin)
            ->from('http://localhost/payroll?month=2026-07')
            ->patch(route('company.switch'), ['company_id' => $asu->id])
            ->assertRedirect('/payroll?month=2026-07');

        $this->assertSame($asu->id, session('company_id'));
    }

    public function test_switch_without_query_returns_to_bare_path(): void
    {
        $admin = $this->admin();
        $baia = Company::where('code', 'BAIA')->firstOrFail();

        $this->actingAs($admin)
            ->from('http://localhost/users')
            ->patch(route('company.switch'), ['company_id' => $baia->id])
            ->assertRedirect('/users');
    }

    public function test_switch_from_deal_card_goes_to_list_without_stale_query(): void
    {
        // Карточка принадлежит прежней фирме — с неё уводим в список,
        // и чужие параметры карточки туда тащить не нужно.
        $admin = $this->admin();
        $asu = Company::where('code', 'ASU')->firstOrFail();

        $this->actingAs($admin)
            ->from('http://localhost/deals/17?tab=finance')
            ->patch(route('company.switch'), ['company_id' => $asu->id])
            ->assertRedirect(route('deals.index'));
    }

    public function test_switch_never_leaves_the_site_even_with_forged_referer(): void
    {
        // Referer подконтролен клиенту: берём из него только путь и параметры.
        $admin = $this->admin();
        $baia = Company::where('code', 'BAIA')->firstOrFail();

        $location = $this->actingAs($admin)
            ->from('https://evil.example.com/payroll?month=2026-07')
            ->patch(route('company.switch'), ['company_id' => $baia->id])
            ->headers->get('Location');

        // Хост чужого referer отброшен — редирект остаётся на своём сайте,
        // а путь с параметрами сохраняется.
        $this->assertStringNotContainsString('evil.example.com', $location);
        $this->assertSame(config('app.url'), parse_url($location, PHP_URL_SCHEME).'://'.parse_url($location, PHP_URL_HOST).':'.parse_url($location, PHP_URL_PORT));
        $this->assertSame('/payroll', parse_url($location, PHP_URL_PATH));
        $this->assertSame('month=2026-07', parse_url($location, PHP_URL_QUERY));
    }
}
