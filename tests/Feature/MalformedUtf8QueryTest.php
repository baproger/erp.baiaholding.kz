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

    /** Доказательство, что тест ловит именно тот баг: без чистки — исключение. */
    public function test_without_sanitizer_the_page_throws_malformed_utf8(): void
    {
        $admin = $this->admin();
        $this->withoutMiddleware(SanitizeUtf8Input::class);
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin)->withHeaders($this->headers())->get(self::URL);
            $this->fail('Ожидалась ошибка Malformed UTF-8');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Malformed UTF-8', $e->getMessage());
        }
    }

    public function test_truncated_utf8_in_query_does_not_break_finance_page(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->withHeaders($this->headers())->get(self::URL)->assertOk();
    }
}
