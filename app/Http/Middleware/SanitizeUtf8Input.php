<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Чистим входные данные от битого UTF-8 (правило от 28.08.2026).
 *
 * Причина: страницы возвращают в Inertia-JSON сырые значения фильтров из
 * адресной строки (?search=…). Если ссылку скопировали с обрезанным русским
 * символом (%D0 без второго байта), json_encode падает с «Malformed UTF-8»
 * и вся страница «Финансы» умирает. Невалидные байты заменяем на «?».
 */
class SanitizeUtf8Input
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->query->replace($this->clean($request->query->all()));
        if (! $request->isJson() && $request->request->count()) {
            $request->request->replace($this->clean($request->request->all()));
        }

        return $next($request);
    }

    /** @param array<mixed> $data @return array<mixed> */
    private function clean(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->clean($v);
            } elseif (is_string($v) && ! mb_check_encoding($v, 'UTF-8')) {
                $data[$k] = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
            }
        }

        return $data;
    }
}
