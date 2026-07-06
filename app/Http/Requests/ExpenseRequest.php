<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        // Чек обязателен при создании расхода; при редактировании — только если заменяют файл.
        $fileRule = $this->isMethod('post') ? ['required'] : ['nullable'];

        return [
            'expenseable_type' => ['nullable', Rule::in(['deal', 'project'])],
            'expenseable_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'exists:expense_categories,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'date' => ['required', 'date'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', Rule::in(['direct', 'indirect'])],
            'status' => ['nullable', Rule::in(['draft', 'confirmed'])],
            'file' => [...$fileRule, 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Прикрепите чек (фото или PDF).',
            'file.mimes' => 'Чек должен быть изображением или PDF.',
            'file.max' => 'Размер чека не должен превышать 10 МБ.',
        ];
    }
}
