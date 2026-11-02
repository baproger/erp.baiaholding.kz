<?php

namespace Tests;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Document;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Смета дизайнера у сделки (гейт «Дизайн и расчет», ТЗ 02.11.2026). */
    protected function giveEstimate(Deal $deal, float $amount = 100000): Document
    {
        return Document::create([
            'documentable_type' => 'deal', 'documentable_id' => $deal->id, 'name' => 'Смета.pdf', 'kind' => 'estimate',
            'amount' => $amount, 'file_path' => 'documents/test.pdf', 'version' => 1, 'size' => 1, 'mime_type' => 'application/pdf',
            'is_active' => true,
        ]);
    }

    /**
     * Сделка, готовая к «Отправить в цех» (ТЗ 02.11.2026): этап «Закуп»,
     * ответственный, подтверждённый расход «Закуп».
     */
    protected function readyForWorkshop(Deal $deal, bool $addPurchase = true): Deal
    {
        $stage = DealStage::funnel($deal->company_id ? (int) $deal->company_id : null)->firstWhere('stage_type', 'shop_gate');
        if (! $stage) {
            // В тестовой воронке (StageSeeder) «Закупа» нет — помечаем текущий этап сделки.
            $stage = DealStage::find($deal->deal_stage_id);
            $stage->update(['stage_type' => 'shop_gate']);
        }
        $deal->update([
            'deal_stage_id' => $stage->id,
            'responsible_user_id' => $deal->responsible_user_id ?? User::factory()->create()->id,
        ]);
        if ($addPurchase) {
            Expense::create(['expenseable_type' => 'deal', 'expenseable_id' => $deal->id, 'amount' => 0,
                'date' => now()->toDateString(), 'status' => 'confirmed', 'type' => 'purchase', 'description' => 'Закуп']);
        }

        return $deal->fresh();
    }
}
