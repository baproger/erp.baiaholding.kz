<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'bin' => ['nullable', 'string', 'max:20'],
            'lot_number' => ['nullable', 'string', 'max:100'],
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
