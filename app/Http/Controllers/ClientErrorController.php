<?php

namespace App\Http\Controllers;

use App\Models\ErrorLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ошибки браузера (JavaScript) в тот же журнал, что и серверные —
 * правило владельца: ВСЕ ошибки сайта видит админ в Аудит → Ошибки.
 * Серверный обработчик их не видит: они происходят уже в браузере, после
 * того как сервер отдал страницу (белый экран План/Факт, 17.09.2026).
 */
class ClientErrorController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'message' => ['required', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:500'],
            'url' => ['nullable', 'string', 'max:500'],
            'stack' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            ErrorLog::create([
                // Префикс «JS:» — в журнале сразу видно, что ошибка браузерная.
                'exception' => 'JS: '.mb_substr($data['name'] ?? 'Error', 0, 180),
                'message' => mb_substr($data['message'], 0, 60000),
                'file' => mb_substr((string) ($data['source'] ?? ''), 0, 255) ?: null,
                'line' => null,
                'url' => mb_substr((string) ($data['url'] ?? $request->headers->get('referer', '')), 0, 512) ?: null,
                'method' => 'BROWSER',
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
                'trace' => mb_substr((string) ($data['stack'] ?? ''), 0, 60000) ?: null,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Журнал недоступен — молчим: сообщение об ошибке не должно
            // порождать ещё одну ошибку.
        }

        return response()->json(['ok' => true]);
    }
}
