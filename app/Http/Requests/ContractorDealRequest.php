<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Форма «+ Сделка подрядчика» (бухгалтер/админ). */
class ContractorDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasAnyRole(['admin', 'financist']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            // Заказчик (как «Название компании» у своих сделок).
            'company_name' => ['required', 'string', 'max:255'],
            // Предмет договора (колонка client_name исторически = товар).
            'client_name' => ['required', 'string', 'max:255'],
            'contractor_name' => ['required', 'string', 'max:255'],
            'budget' => ['required', 'numeric', 'min:0.01'],
            'commission_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'bin' => ['nullable', 'string', 'max:100'],
            'contract_date' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:255'],
            'deadline' => ['nullable', 'date'],
            'responsible_user_id' => ['nullable', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'company_name' => 'заказчик', 'client_name' => 'предмет договора', 'contractor_name' => 'подрядчик',
            'budget' => 'сумма договора', 'commission_pct' => 'наш процент',
        ];
    }
}
