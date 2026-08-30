<?php

namespace App\Http\Inertia;

use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

/**
 * Второй ремень от «Malformed UTF-8» (31.08.2026): чистим уже ГОТОВЫЕ данные
 * страницы перед json_encode. Первый ремень (SanitizeUtf8Input) чистит вход;
 * этот ловит битые байты из любого источника (БД, PHP-обработка) — страница
 * не падает, а в журнал ошибок пишется, КАКОЕ именно поле было битым,
 * чтобы починить данные в корне.
 */
class SanitizedInertiaResponse extends InertiaResponse
{
    public function resolveProperties(Request $request, array $props): array
    {
        $props = parent::resolveProperties($request, $props);

        $bad = [];
        $props = $this->sanitize($props, '', $bad);

        if ($bad !== []) {
            report(new \RuntimeException(
                'Malformed UTF-8 в данных страницы (заменено на «?»): '.implode(', ', array_slice($bad, 0, 20))
            ));
        }

        return $props;
    }

    /** @param array<mixed> $data @param list<string> $bad @return array<mixed> */
    private function sanitize(array $data, string $path, array &$bad): array
    {
        foreach ($data as $key => $value) {
            $here = $path === '' ? (string) $key : $path.'.'.$key;
            if (is_array($value)) {
                $data[$key] = $this->sanitize($value, $here, $bad);
            } elseif (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $data[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                $bad[] = $here;
            }
        }

        return $data;
    }
}
