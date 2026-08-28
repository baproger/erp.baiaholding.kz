<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SetLocale runs after StartSession (session available) but before
        // controllers, so localized stage names use the correct locale.
        $middleware->web(append: [
            // Первым: битый UTF-8 в ?query (обрезанная ссылка) ронял JSON страниц.
            \App\Http\Middleware\SanitizeUtf8Input::class,
            \App\Http\Middleware\SecureHeaders::class,
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\SetCurrentCompany::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Журнал ошибок (Аудит → Ошибки, только админ): пишем каждую СЕРВЕРНУЮ
        // ошибку в БД. Клиентские (404/403/419, валидация, разлогин) не шумят.
        // Запись в try/catch: падение журнала не должно прятать саму ошибку.
        $exceptions->report(function (\Throwable $e): void {
            if ($e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException
                || $e instanceof \Illuminate\Auth\Access\AuthorizationException
                || $e instanceof \Illuminate\Session\TokenMismatchException
                || ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $e->getStatusCode() < 500)) {
                return;
            }
            try {
                \App\Models\ErrorLog::create([
                    'exception' => mb_substr(get_class($e), 0, 191),
                    'message' => mb_substr($e->getMessage() ?: '(без сообщения)', 0, 60000),
                    'file' => mb_substr($e->getFile(), 0, 255),
                    'line' => (int) $e->getLine(),
                    'url' => request() ? mb_substr(request()->fullUrl(), 0, 512) : null,
                    'method' => request()?->method(),
                    'user_id' => auth()->id(),
                    'ip' => request()?->ip(),
                    'trace' => mb_substr($e->getTraceAsString(), 0, 60000),
                    'created_at' => now(),
                ]);
            } catch (\Throwable) {
                // журнал недоступен (миграция не накатана, БД лежит) — молчим,
                // стандартный лог-файл Laravel всё равно получит ошибку
            }
        });
    })->create();
