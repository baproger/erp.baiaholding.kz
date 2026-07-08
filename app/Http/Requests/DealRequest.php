<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // «Количество» приходит из числового поля как number — колонка строковая.
        if ($this->has('lot_number') && $this->lot_number !== null) {
            $this->merge(['lot_number' => (string) $this->lot_number]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'client_name' => ['required', 'string', 'max:255'],
            'company_name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            // В UI поле называется «Номер договора» (историческое имя колонки — bin).
            'bin' => ['nullable', 'string', 'max:100'],
            'contract_date' => ['nullable', 'date'],
            // В UI — «Количество» (историческое имя колонки — lot_number) + ед. изм.
            'lot_number' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', \Illuminate\Validation\Rule::in(\App\Models\Deal::UNITS)],
            'source' => ['nullable', \Illuminate\Validation\Rule::in(\App\Models\Deal::SOURCES)],
            'client_id' => ['nullable', 'exists:clients,id'],
            'responsible_user_id' => ['nullable', 'exists:users,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'budget' => ['required', 'numeric', 'min:0'],
            'deadline' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'note' => ['nullable', 'string'],
            // deal_stage_id и status намеренно НЕ принимаются здесь: смена этапа/статуса идёт
            // только через updateStage → StageTransitionService (гейты оплаты/задач/порядка).
            // Иначе обычный update позволял бы выставить won-этап без оплаты и накрутить бонус.
        ];
    }
}
