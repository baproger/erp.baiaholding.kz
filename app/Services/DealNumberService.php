<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Deal;
use Illuminate\Support\Facades\DB;

class DealNumberService
{
    /**
     * Generate a unique deal number per company, e.g. BAIA-001 / ASU-001.
     * The prefix is the company code; the sequence runs per prefix.
     * Uses a row-locking transaction to avoid race conditions.
     */
    public function generate(?Company $company = null): string
    {
        $prefix = ($company?->code ?: 'BAIA').'-';

        return DB::transaction(function () use ($prefix) {
            // Максимум числового суффикса СТРОГОГО формата {CODE}-NNN. Легаси-номера
            // (BAIA-2025-0042, BAIA-TEST-1) игнорируются — иначе счётчик откатывается
            // и упирается в unique(number). Парсим в PHP: sqlite (тесты) без REGEXP.
            $max = (int) Deal::withTrashed()
                ->where('number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->pluck('number')
                ->map(fn ($n) => preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $n, $m) ? (int) $m[1] : 0)
                ->max();

            return $prefix.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
        });
    }
}
