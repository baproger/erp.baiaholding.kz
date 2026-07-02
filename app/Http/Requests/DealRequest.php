<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'lot_number' => ['nullable', 'string', 'max:100'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'responsible_user_id' => ['nullable', 'exists:users,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'deal_stage_id' => ['nullable', 'exists:deal_stages,id'],
            'budget' => ['required', 'numeric', 'min:0'],
            'deadline' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'note' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'closed', 'cancelled'])],
        ];
    }
}
