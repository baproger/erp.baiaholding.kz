<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Подрядные сделки (правило владельца от 30.09.2026).
 *
 * Заказчик платит компании всю сумму договора (аванс/оплата вносятся в системе
 * как обычно), компания оставляет себе commission_pct и перечисляет остальное
 * подрядчику. Перечисление — обычный расход сделки типа «contractor»: он
 * создаётся сразу как заявка (pending), бухгалтер подтверждает его, когда
 * реально перевёл деньги, и выбирает кассу (нал/банк) — так общая касса
 * сходится: приход всей суммы, расход подрядчику, остаток = наш процент.
 *
 * Бонус по подрядным сделкам не начисляется никому (bonus_rate_override = 0),
 * в цех они не идут, этапы: Договор → Акт → ЭСФ → Оплата → Тендер закрыт.
 */
class ContractorDealService
{
    /** Этапы производства — подрядная сделка их пропускает. */
    public const SKIPPED_STAGE_TYPES = ['design', 'shop_gate', 'logistics', 'assembly'];

    public function __construct(private readonly DealNumberService $numbers) {}

    /** @param array<string, mixed> $data валидированные поля ContractorDealRequest */
    public function create(array $data, User $by, ?Company $company): Deal
    {
        return DB::transaction(function () use ($data, $by, $company) {
            $deal = Deal::create([
                'kind' => Deal::KIND_CONTRACTOR,
                'company_id' => $company?->id,
                'number' => $this->numbers->generate($company),
                'name' => $data['company_name'],
                'company_name' => $data['company_name'],
                'client_name' => $data['client_name'],
                'contractor_name' => $data['contractor_name'],
                'commission_pct' => $data['commission_pct'],
                'bin' => $data['bin'] ?? null,
                'contract_date' => $data['contract_date'] ?? null,
                'address' => $data['address'] ?? null,
                'budget' => $data['budget'],
                'deadline' => $data['deadline'] ?? null,
                'note' => $data['note'] ?? null,
                'description' => $data['description'] ?? null,
                'responsible_user_id' => $data['responsible_user_id'] ?? $by->id,
                // Партнёрской доли нет, бонуса нет — наш доход только процент.
                'partner_pct' => 0,
                'bonus_rate_override' => 0,
                'status' => 'active',
                'deal_stage_id' => DealStage::funnel($company?->id)->first()?->id,
            ]);
            $this->syncPayout($deal, $by);

            return $deal;
        });
    }

    /**
     * Заявка на перечисление подрядчику = сумма − наш %. Пока не подтверждена —
     * следует за изменением суммы/процента; подтверждённую не трогаем.
     */
    public function syncPayout(Deal $deal, ?User $by = null): ?Expense
    {
        if (! $deal->isContractor()) {
            return null;
        }
        $amount = $deal->contractorPayout();
        $description = 'Перечисление подрядчику: '.($deal->contractor_name ?: '—')
            .' ('.rtrim(rtrim(number_format((float) $deal->commission_pct, 2, '.', ''), '0'), '.').'% наши)';

        $existing = Expense::where('expenseable_type', 'deal')->where('expenseable_id', $deal->id)
            ->where('type', Expense::TYPE_CONTRACTOR)->orderByDesc('id')->first();
        if ($existing && $existing->status !== 'pending') {
            return $existing;
        }
        if ($existing) {
            $existing->update(['amount' => $amount, 'description' => $description]);

            return $existing;
        }
        if ($amount <= 0) {
            return null;
        }

        return Expense::create([
            'expenseable_type' => 'deal',
            'expenseable_id' => $deal->id,
            'company_id' => $deal->company_id,
            'amount' => $amount,
            'date' => now()->toDateString(),
            'type' => Expense::TYPE_CONTRACTOR,
            'status' => 'pending',
            'description' => $description,
            'responsible_user_id' => $by?->id ?? $deal->responsible_user_id,
        ]);
    }
}
