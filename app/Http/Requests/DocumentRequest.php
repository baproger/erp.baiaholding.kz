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
            // contract — файл договора (проверка «Договор» в сопровождении, ТЗ 02.11.2026).
            'kind' => ['nullable', Rule::in(['estimate', 'contract'])],
            // Сумма сметы — необязательна: дизайнер суммы не считает (уточнение владельца 03.11.2026).
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
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
