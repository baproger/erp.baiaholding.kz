<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        // Чек при создании НЕ обязателен: если менеджер его не приложил,
        // бухгалтер прикрепит при подтверждении (без чека подтвердить нельзя).
        $fileRule = ['nullable'];

        return [
            'expenseable_type' => ['nullable', Rule::in(['deal', 'project'])],
            'expenseable_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'exists:expense_categories,id'],
            // Расход по материалам: позиция склада + количество; сумма считается
            // на сервере (количество × закупочная цена), вручную — только если
            // у материала цена не заведена.
            'material_id' => ['nullable', 'exists:materials,id'],
            'qty' => ['required_with:material_id', 'nullable', 'numeric', 'min:0.01'],
            'amount' => ['required_without:material_id', 'nullable', 'numeric', 'min:0'],
            'date' => ['required', 'date'],
            'description' => ['nullable', 'string'],
            // direct — прочий (чек), delivery — доставка, purchase — закуп
            'type' => ['nullable', Rule::in(['direct', 'indirect', 'delivery', 'purchase', 'assembly', 'metal', 'sheet', 'fittings', 'contractor'])],
            'status' => ['nullable', Rule::in(['draft', 'pending', 'confirmed'])],
            // kaspi — Kaspi перевод (безнал, считается в банковском остатке) — 03.11.2026.
            'payment_method' => ['nullable', Rule::in(['cash', 'bank', 'kaspi'])],
            'file' => [...$fileRule, 'required_if:funding,debt', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:10240'],
            // «Оплачено деньгами» / «В долг» (ТЗ 02.11.2026, п.3.1). Для долга —
            // поставщик, контакт (телефон/БИН), срок погашения и документ обязательны.
            'funding' => ['nullable', Rule::in(['cash', 'debt'])],
            'supplier_name' => ['nullable', 'required_if:funding,debt', 'string', 'max:255'],
            'supplier_contact' => ['nullable', 'required_if:funding,debt', 'string', 'max:120'],
            'debt_due_date' => ['nullable', 'required_if:funding,debt', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Прикрепите чек (фото или PDF).',
            'file.mimes' => 'Чек должен быть изображением или PDF.',
            'file.max' => 'Размер чека не должен превышать 10 МБ.',
            'file.required_if' => 'Для расхода «в долг» прикрепите счёт или накладную поставщика.',
            'supplier_name.required_if' => 'Укажите поставщика.',
            'supplier_contact.required_if' => 'Укажите телефон или БИН поставщика.',
            'debt_due_date.required_if' => 'Укажите срок погашения долга.',
        ];
    }
}
