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
        $this->assertOwnership($request->user(), $this->resolve($request->input('expenseable_type', 'deal'), (int) $request->input('expenseable_id')));

        $data = $request->validated();
        unset($data['file']);
        // Чек хранится вне public-корня (storage/app/private), как и документы.
        $data['file_path'] = $request->file('file')->store('receipts', 'local');
        // Автор проставляется автоматически — заполнить расход за другого нельзя.
        $data['responsible_user_id'] = $request->user()->id;
        $data['type'] ??= 'direct';
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
        $expense->delete();

        return back()->with('success', 'Расход удалён.');
    }
}
