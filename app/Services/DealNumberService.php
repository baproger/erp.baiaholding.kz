<?php

namespace App\Services;

use App\Models\Deal;
use Illuminate\Support\Facades\DB;

class DealNumberService
{
    /**
     * Generate a unique deal number in the format BAIA-{year}-{sequence}.
     * Uses a row-locking transaction to avoid race conditions.
     */
    public function generate(): string
    {
        $year = now()->year;
        $prefix = "BAIA-{$year}-";

        return DB::transaction(function () use ($prefix) {
            $last = Deal::withTrashed()
                ->where('number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('number');

            $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

            return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        });
    }
}
