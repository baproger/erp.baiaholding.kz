<?php

namespace App\Services;

use App\Models\Deal;
use Illuminate\Support\Facades\DB;

class DealNumberService
{
    /**
     * Generate a unique deal number in the format BAIA-{sequence}, e.g. BAIA-0015.
     * Uses a row-locking transaction to avoid race conditions.
     */
    public function generate(): string
    {
        $prefix = 'BAIA-';

        return DB::transaction(function () use ($prefix) {
            $last = Deal::withTrashed()
                ->where('number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('number');

            // Sequence = the trailing number after the last dash. Works with the new
            // BAIA-NNNN format and any legacy BAIA-{year}-NNNN numbers.
            $next = $last ? ((int) substr(strrchr($last, '-'), 1)) + 1 : 1;

            return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        });
    }
}
