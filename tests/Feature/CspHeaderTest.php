<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** CSP (09.09.2026): на проде строгая политика без unsafe-eval, локально её нет (Vite HMR). */
class CspHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_sends_strict_csp_without_unsafe_eval(): void
    {
        $this->app['env'] = 'production';
        $res = $this->get('/login');

        $csp = $res->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp, 'на проде заголовок CSP обязателен');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self' 'nonce-", $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        // Инлайн-скрипт Ziggy (@routes) несёт тот же nonce — иначе рухнут все страницы.
        preg_match("/'nonce-([^']+)'/", $csp, $m);
        $this->assertStringContainsString('nonce="'.$m[1].'"', $res->getContent());
    }

    public function test_local_env_has_no_csp(): void
    {
        $this->get('/login')->assertHeaderMissing('Content-Security-Policy');
    }
}
