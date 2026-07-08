<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExpenseRequest;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        // Прочий расход: бухгалтер/админ подтверждают сразу; расход менеджера
        // (и директора) ждёт подтверждения бухгалтера — чек + нал/банк.
        $isAccountant = $request->user()->hasAnyRole(['admin', 'financist']);
        $data['status'] = $isAccountant ? 'confirmed' : 'pending';
        if ($isAccountant) {
            $data['confirmed_by'] = $request->user()->id;
            $data['confirmed_at'] = now();
        } else {
            unset($data['payment_method']); // способ оплаты выбирает бухгалтер
        }

        $expense = Expense::create($data);

        if (! $isAccountant) {
            $this->notifyAccountants($expense, $entity);

            return back()->with('success', 'Расход отправлен бухгалтеру на подтверждение.');
        }

        return back()->with('success', 'Расход добавлен.');
    }

    /**
     * Бухгалтеру: уведомление + задача «Подтвердить расход…» на сделке/заказе.
     */
    private function notifyAccountants(Expense $expense, ?Model $entity): void
    {
        $title = 'Подтвердить расход #'.$expense->id.' — '.number_format((float) $expense->amount, 0, '.', ' ').' ₸'
            .($entity?->number ? ' ('.$entity->number.')' : '');

        $financists = User::where('is_active', true)->role('financist')->get();
        foreach ($financists as $fin) {
            if ($entity && method_exists($entity, 'tasks')) {
                $entity->tasks()->create([
                    'title' => $title,
                    'status' => 'new',
                    'priority' => 'high',
                    'assignee_id' => $fin->id,
                    'creator_id' => $expense->responsible_user_id ?? $fin->id,
                    'start_date' => now(),
                    'due_date' => now()->addDays(3),
                ]);
            }
            $fin->notify(new \App\Notifications\ExpensePending($expense));
        }
    }

    /**
     * Подтверждение расхода бухгалтером: обязательный чек (уже приложенный или
     * загружаемый сейчас) + способ оплаты нал/банк (касса).
     */
    public function confirm(Request $request, Expense $expense): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'financist']), 403, 'Расход подтверждает бухгалтер или админ.');
        $this->assertOwnership($request->user(), $expense->expenseable);

        if ($expense->status === 'confirmed') {
            return back()->with('error', 'Расход уже подтверждён.');
        }

        $data = $request->validate([
            'payment_method' => ['required', \Illuminate\Validation\Rule::in(['cash', 'bank'])],
            'file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:10240'],
        ], ['payment_method.required' => 'Выберите способ оплаты: наличные или банк.']);

        if ($request->hasFile('file')) {
            if ($expense->file_path) {
                Storage::disk('local')->delete($expense->file_path);
            }
            $expense->file_path = $request->file('file')->store('receipts', 'local');
        }
        if (! $expense->file_path) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'file' => 'Без чека расход не подтверждается — прикрепите фото или PDF.',
            ]);
        }

        $expense->update([
            'file_path' => $expense->file_path,
            'status' => 'confirmed',
            'payment_method' => $data['payment_method'],
            'confirmed_by' => $request->user()->id,
            'confirmed_at' => now(),
        ]);

        // Закрываем задачи «Подтвердить расход #N …» у бухгалтеров.
        \App\Models\Task::where('title', 'like', 'Подтвердить расход #'.$expense->id.' %')
            ->where('status', '!=', 'done')
            ->get()->each(fn ($t) => $t->update(['status' => 'done', 'completed_at' => now()]));

        // Автору — уведомление о подтверждении.
        $expense->responsible?->notify(new \App\Notifications\ExpenseConfirmed($expense));

        return back()->with('success', 'Расход подтверждён ('.($data['payment_method'] === 'cash' ? 'наличные' : 'банк').').');
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
