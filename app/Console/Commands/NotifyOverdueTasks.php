<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Notifications\TaskOverdue;
use Illuminate\Console\Command;

class NotifyOverdueTasks extends Command
{
    protected $signature = 'tasks:notify-overdue';

    protected $description = 'Notify assignees of newly overdue tasks (once each).';

    public function handle(): int
    {
        $tasks = Task::query()
            ->where('status', '!=', 'done')
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->whereNull('overdue_notified_at')
            ->whereNotNull('assignee_id')
            ->with('assignee')
            ->get();

        foreach ($tasks as $task) {
            $task->assignee?->notify(new TaskOverdue($task));
            $task->forceFill(['overdue_notified_at' => now()])->saveQuietly();
        }

        $this->info("Notified {$tasks->count()} overdue task(s).");

        return self::SUCCESS;
    }
}
