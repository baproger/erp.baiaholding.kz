<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'documentable_type' => ['required', Rule::in(['deal', 'project'])],
            'documentable_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            // 'estimate' — смета дизайнера (видна бухгалтеру при подтверждении материалов).
            'kind' => ['nullable', Rule::in(['estimate'])],
            // Сумма сметы (ТЗ 02.11.2026, п.2.1) — обязательна для сметы.
            'amount' => ['nullable', 'required_if:kind,estimate', 'numeric', 'min:1', 'max:999999999999'],
            'file' => ['required', 'file', 'max:20480', // 20MB
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg,gif,zip,rar,txt,csv'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.required_if' => 'Укажите сумму сметы.',
            'amount.numeric' => 'Сумма сметы — числом.',
            'amount.min' => 'Сумма сметы должна быть больше нуля.',
            'file.required' => 'Выберите файл.',
            'file.mimes' => 'Формат файла: PDF, Excel, Word, фото или архив.',
            'file.max' => 'Файл — не больше 20 МБ.',
        ];
    }
}
