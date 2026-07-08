<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExpenseRequest;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseController extends Controller
{
    private function assertOwnership(User $user, ?Model $entity): void
    {
        // Изоляция фирм: расходы чужой компании (BAIA/ASU) недоступны никому,
        // кто к этой компании не привязан, — включая финансиста и директора.
        $companyId = $entity instanceof Project ? $entity->deal?->company_id : $entity?->company_id;
        abort_unless($entity === null || $user->worksInCompany($companyId ? (int) $companyId : null), 403);

        if ($user->hasRole('manager') && ! $user->hasAnyRole(['admin', 'director', 'financist'])) {
            abort_unless($entity && $entity->responsible_user_id === $user->id, 403);
        }
    }

    private function resolve(?string $type, ?int $id): ?Model
    {
        if (! $id) {
            return null;
        }

        return $type === 'project' ? Project::find($id) : Deal::find($id);
    }

    public function store(ExpenseRequest $request): RedirectResponse
    {
        $this->authorize('create', Expense::class);
        $entity = $this->resolve($request->input('expenseable_type', 'deal'), (int) $request->input('expenseable_id'));
        $this->assertOwnership($request->user(), $entity);

        $data = $request->validated();
        unset($data['file']);
        // Чек хранится вне public-корня (storage/app/private), как и документы.
        $data['file_path'] = $request->hasFile('file') ? $request->file('file')->store('receipts', 'local') : null;
        // Автор проставляется автоматически — заполнить расход за другого нельзя.
        $data['responsible_user_id'] = $request->user()->id;
        $data['type'] ??= 'direct';

        // Расход по материалам: списываем остаток со склада компании сделки.
        if (! empty($data['material_id'])) {
            $material = \App\Models\Material::findOrFail($data['material_id']);

            $entityCompanyId = $entity instanceof Project ? $entity->deal?->company_id : $entity?->company_id;
            if ($material->company_id && (int) $material->company_id !== (int) $entityCompanyId) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'material_id' => 'Материал со склада другой компании.',
                ]);
            }
            if ((float) $material->quantity < (float) $data['qty']) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'qty' => 'Недостаточно на складе: остаток '.rtrim(rtrim(number_format((float) $material->quantity, 2, '.', ' '), '0'), '.').' '.$material->unit.'.',
                ]);
            }

            // Внутреннее списание — подтверждение бухгалтера не требуется.
            $data['status'] = 'confirmed';
            $data['description'] = trim(($data['description'] ?? '')) !== ''
                ? $data['description']
                : 'Материал: '.$material->name.' × '.rtrim(rtrim(number_format((float) $data['qty'], 2, '.', ''), '0'), '.').' '.$material->unit;

            \Illuminate\Support\Facades\DB::transaction(function () use ($data, $material) {
                Expense::create($data);
                $material->decrement('quantity', $data['qty']);
            });

            return back()->with('success', 'Расход по материалам добавлен — остаток на складе списан.');
        }

        $data['status'] ??= 'draft';
        Expense::create($data);

        return back()->with('success', 'Расход добавлен.');
    }

    public function update(ExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $this->authorize('update', $expense);
        $this->assertOwnership($request->user(), $expense->expenseable);

        $data = $request->validated();
        unset($data['file']);
        // Материал/количество после создания не меняются (иначе разъедется склад) —
        // удалите расход (остаток вернётся) и создайте заново.
        unset($data['material_id'], $data['qty']);
        if ($request->hasFile('file')) {
            if ($expense->file_path) {
                Storage::disk('local')->delete($expense->file_path);
            }
            $data['file_path'] = $request->file('file')->store('receipts', 'local');
        }
        // responsible_user_id намеренно не трогаем — автор расхода неизменен.
        $expense->update($data);

        return back()->with('success', 'Расход обновлён.');
    }

    public function receipt(Expense $expense): StreamedResponse
    {
        $this->authorize('view', $expense);
        $this->assertOwnership(request()->user(), $expense->expenseable);

        abort_unless($expense->file_path && Storage::disk('local')->exists($expense->file_path), 404);

        $name = 'чек-' . $expense->id . '.' . pathinfo($expense->file_path, PATHINFO_EXTENSION);

        // Отдаём inline — чек открывается в браузере на просмотр, без скачивания.
        // nosniff: файл загружен пользователем, запрещаем браузеру угадывать тип (защита от XSS).
        return Storage::disk('local')->response($expense->file_path, $name, [
            'Content-Disposition' => 'inline; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $this->authorize('delete', $expense);
        $this->assertOwnership(request()->user(), $expense->expenseable);

        // Удаление расхода по материалам возвращает количество на склад.
        \Illuminate\Support\Facades\DB::transaction(function () use ($expense) {
            if ($expense->material_id && $expense->qty && $expense->material) {
                $expense->material->increment('quantity', $expense->qty);
            }
            $expense->delete();
        });

        return back()->with('success', $expense->material_id ? 'Расход удалён — остаток возвращён на склад.' : 'Расход удалён.');
    }
}
