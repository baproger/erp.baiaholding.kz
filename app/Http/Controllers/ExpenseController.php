<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExpenseRequest;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;

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
        $expense->update($request->validated());

        return back()->with('success', 'Расход обновлён.');
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $this->authorize('delete', $expense);
        $this->assertOwnership(request()->user(), $expense->expenseable);
        $expense->delete();

        return back()->with('success', 'Расход удалён.');
    }
}
