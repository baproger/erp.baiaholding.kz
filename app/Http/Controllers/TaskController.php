<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskRequest;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    // Tasks are managed inline inside deal/project cards (TaskPanel). There is no
    // standalone tasks board, so only the mutation endpoints below are exposed.

    public function store(TaskRequest $request): RedirectResponse
    {
        $this->authorize('create', Task::class);

        $data = $request->validated();
        $data['creator_id'] = $request->user()->id;
        $data['priority'] ??= 'medium';
        $data['status'] ??= 'new';

        $task = Task::create($data);

        if ($task->assignee_id && $task->assignee_id !== $request->user()->id) {
            $task->assignee?->notify(new \App\Notifications\TaskAssigned($task));
        }

        return back()->with('success', 'Задача создана.');
    }

    public function update(TaskRequest $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);
        $task->update($request->validated());

        return back()->with('success', 'Задача обновлена.');
    }

    public function updateStatus(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);

        $validated = $request->validate([
            'status' => ['required', 'in:new,in_progress,review,done'],
        ]);

        $task->status = $validated['status'];
        $task->completed_at = $validated['status'] === 'done' ? now() : null;
        $task->save();

        return back()->with('success', 'Статус задачи обновлён.');
    }

    public function destroy(Task $task): RedirectResponse
    {
        $this->authorize('delete', $task);
        $task->delete();

        return back()->with('success', 'Задача удалена.');
    }
}
