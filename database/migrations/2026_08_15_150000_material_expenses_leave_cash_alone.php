<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Списание материала со склада — внутреннее движение запаса: деньги ушли при
 * ЗАКУПЕ (расход прихода склада), поэтому у списания способа оплаты быть не
 * должно — иначе касса уменьшалась дважды. Разово чистим старые записи;
 * новые создаются уже без payment_method (ExpenseController::store).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('expenses')->whereNotNull('material_id')
            ->whereNotNull('payment_method')
            ->update(['payment_method' => null]);
    }

    public function down(): void
    {
        // Прежние значения не восстановить — откат не требуется.
    }
};
