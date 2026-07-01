<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExpenseRequest;
use App\Models\Expense;
use Illuminate\Http\RedirectResponse;

class ExpenseController extends Controller
{
    public function store(ExpenseRequest $request): RedirectResponse
    {
        $this->authorize('create', Expense::class);

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
        $expense->update($request->validated());

        return back()->with('success', 'Расход обновлён.');
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $this->authorize('delete', $expense);
        $expense->delete();

        return back()->with('success', 'Расход удалён.');
    }
}
