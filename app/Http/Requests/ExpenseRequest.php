<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'expenseable_type' => ['nullable', Rule::in(['deal', 'project'])],
            'expenseable_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'exists:expense_categories,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'date' => ['required', 'date'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', Rule::in(['direct', 'indirect'])],
            'status' => ['nullable', Rule::in(['draft', 'confirmed'])],
        ];
    }
}
