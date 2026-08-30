<?php

namespace Tests\Feature;

use App\Http\Middleware\SanitizeUtf8Input;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Регресс 28.08.2026: ?search=%D0 (обрезанный русский символ в ссылке)
 * ронял «Финансы» ошибкой «Malformed UTF-8 characters» — фильтры
 * возвращаются в Inertia-JSON как есть. Теперь вход чистится middleware.
 */
class MalformedUtf8QueryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/finance?search=%D0&rc_search=%D0%9F%D1';

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->companies()->attach(Company::where('code', 'BAIA')->firstOrFail()->id);

        return $admin;
    }

    private function headers(): array
    {
        // Версия — та же, что считает HandleInertiaRequests (иначе Inertia ответит 409).
        $version = (new \App\Http\Middleware\HandleInertiaRequests)->version(request());

        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version];
    }

    /**
     * Два ремня: даже БЕЗ входной чистки (SanitizeUtf8Input) страница живёт —
     * выходной санитайзер (SanitizedInertiaResponse) чистит готовые данные.
     * До 31.08.2026 этот запрос ронял страницу InvalidArgumentException'ом.
     */
    public function test_page_survives_even_without_input_sanitizer(): void
    {
        $admin = $this->admin();
        $this->withoutMiddleware(SanitizeUtf8Input::class);
        $this->withoutExceptionHandling();

        $this->actingAs($admin)->withHeaders($this->headers())->get(self::URL)->assertOk();
    }

    public function test_truncated_utf8_in_query_does_not_break_finance_page(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->withHeaders($this->headers())->get(self::URL)->assertOk();
    }

    /** 31.08.2026: битые байты в ДАННЫХ (например, заметка поступления) тоже не роняют страницу. */
    public function test_malformed_utf8_in_db_data_is_sanitized_not_fatal(): void
    {
        $admin = $this->admin();
        \App\Models\CashReceipt::create([
            'company_id' => Company::where('code', 'BAIA')->firstOrFail()->id,
            'amount' => 1000, 'method' => 'cash', 'source' => "Битая строка \xC3", 'date' => now()->toDateString(),
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->withHeaders($this->headers())->get('/finance')->assertOk();

        // Факт и место попали в журнал ошибок — данные можно чинить в корне.
        $this->assertTrue(\App\Models\ErrorLog::where('message', 'like', '%Malformed UTF-8 в данных страницы%')->exists());
    }

    /**
     * Корень бага 31.08.2026: trim($s, ' ·') режет маску ПОБАЙТОВО и отрезает
     * половину буквы «з» (D0 B7 ↔ «·» C2 B7) в подписи ссылки счёта.
     * Заказчик, оканчивающийся на «з», не должен ломать подпись.
     */
    public function test_invoice_link_label_keeps_trailing_cyrillic_ze(): void
    {
        $admin = $this->admin();
        $deal = \App\Models\Deal::create([
            'company_id' => Company::where('code', 'BAIA')->firstOrFail()->id,
            'number' => 'BAIA-001', 'name' => 'Сделка', 'company_name' => 'ТОО КазМунайГаз',
            'budget' => 100000, 'status' => 'active',
            'deal_stage_id' => \App\Models\DealStage::orderBy('order')->first()->id,
            'responsible_user_id' => $admin->id,
        ]);
        \App\Models\Invoice::create([
            'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id,
            'number' => 'INV-1', 'amount' => 50000, 'status' => 'sent',
            'issue_date' => now()->subMonths(2)->toDateString(),
            'created_at' => now()->subMonths(2),
        ]);

        $res = $this->actingAs($admin)->withHeaders($this->headers())->get('/finance')->assertOk();
        $labels = collect(data_get($res->json('props'), 'invoicesPast.*.link.label'))->filter()->values();
        $this->assertTrue($labels->contains(fn ($l) => str_ends_with($l, 'КазМунайГаз')), 'буква «з» на конце должна уцелеть: '.$labels->implode(' | '));
        $this->assertFalse(\App\Models\ErrorLog::where('message', 'like', '%Malformed UTF-8 в данных страницы%')->exists(), 'журнал должен остаться пустым');
    }
}
