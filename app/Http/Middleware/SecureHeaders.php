<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Базовые security-заголовки: запрет MIME-sniffing, запрет встраивания
 * в чужие iframe (кликджекинг), скупой Referrer, отключение ненужных
 * браузерных API; HSTS — только когда сайт уже открыт по HTTPS.
 */
class SecureHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // CSP (правило от 09.09.2026, https://web.dev/articles/csp): скрипты —
        // только свои и с nonce, БЕЗ 'unsafe-eval' (Vue собран без компилятора
        // шаблонов, eval нам не нужен). Только на проде: локальный Vite dev
        // (порт 5173, HMR) политика бы сломала.
        $prod = app()->isProduction();
        if ($prod) {
            \Illuminate\Support\Facades\Vite::useCspNonce();
        }

        $response = $next($request);

        if ($prod) {
            $nonce = \Illuminate\Support\Facades\Vite::cspNonce();
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'",
                // 'unsafe-inline' для стилей: Vue ставит инлайновые style-атрибуты.
                "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
                "font-src 'self' data: https://fonts.bunny.net",
                "img-src 'self' data: blob:",
                "connect-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'self'",
            ]));
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
