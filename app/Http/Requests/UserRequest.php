<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => [$userId ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            'department_id' => ['nullable', 'exists:departments,id'],
            // Доступ к цехам (Металл/Ағаш); пусто = все цеха.
            'workshops' => ['nullable', 'array'],
            'workshops.*' => ['string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'hired_at' => ['nullable', 'date'],
            'salary' => ['nullable', 'numeric', 'min:0'],
            // Трудовой договор — необязательный файл (PDF/фото/скан).
            'contract' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx', 'max:10240'],
            'role' => ['required', Rule::exists('roles', 'name')],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Русские сообщения формы сотрудника: файла lang/ru/validation.php в проекте
     * нет, без этого бухгалтер видел английские ошибки (13.10.2026).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Укажите ФИО сотрудника.',
            'email.required' => 'Укажите email — это логин для входа.',
            'email.email' => 'Email указан неверно.',
            'email.unique' => 'Сотрудник с таким email уже есть. Если он уволен — найдите его на вкладке «Уволенные» и нажмите «Восстановить».',
            'password.required' => 'Придумайте пароль (минимум 8 символов).',
            'password.confirmed' => 'Пароли не совпадают.',
            'password.min' => 'Пароль — минимум 8 символов.',
            'password.uncompromised' => 'Этот пароль слишком простой или уже утёк в интернет — придумайте другой.',
            'role.required' => 'Выберите роль.',
            'role.exists' => 'Такой роли нет.',
            'department_id.exists' => 'Такого отдела нет.',
            'birth_date.before' => 'Дата рождения должна быть в прошлом.',
            'salary.numeric' => 'Оклад — числом.',
            'salary.min' => 'Оклад не может быть отрицательным.',
            'contract.mimes' => 'Договор — PDF, фото (JPG/PNG/WEBP) или Word.',
            'contract.max' => 'Файл договора — не больше 10 МБ.',
        ];
    }
}
