<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by controller policy calls.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'head_user_id' => ['nullable', 'exists:users,id'],
            'is_active' => ['boolean'],
            // Фирмы, в которых создаётся отдел (только при создании; пусто = текущая).
            'company_ids' => ['sometimes', 'array'],
            'company_ids.*' => ['integer', 'exists:companies,id'],
        ];
    }
}
