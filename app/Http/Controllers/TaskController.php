<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskRequest;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Task::class);

        // Personal board: tasks assigned to (or created by) the current user.
        $tasks = Task::query()
            ->with(['assignee:id,name', 'taskable'])
            ->when(! $request->user()->hasAnyRole(['admin', 'director']), function ($query) use ($request) {
                $uid = $request->user()->id;
                $query->where(fn ($q) => $q
                    ->where('assignee_id', $uid)
                    ->orWhere('creator_id', $uid)
                    ->orWhereHasMorph('taskable', [\App\Models\Deal::class, \App\Models\Project::class], fn ($m) => $m->where('responsible_user_id', $uid)));
            })
            ->when($request->string('assignee')->toString(), fn ($q, $a) => $q->where('assignee_id', $a))
            ->latest()
            ->get();

        return Inertia::render('Tasks/Index', [
            'tasks' => $tasks,
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

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
