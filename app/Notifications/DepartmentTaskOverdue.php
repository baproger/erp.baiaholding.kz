<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Руководителю отдела: у сотрудника его отдела просрочена задача.
 * Сам исполнитель получает обычный TaskOverdue.
 */
class DepartmentTaskOverdue extends Notification
{
    use Queueable;

    public function __construct(public Task $task, public string $assigneeName) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        // Руководителю — туда, где задача: карточка сделки/заказа, а без
        // привязки — профиль сотрудника-исполнителя.
        $t = $this->task->taskable;
        $url = match (true) {
            $t instanceof \App\Models\Project => route('projects.show', $t->id, absolute: false),
            $t !== null => route('deals.show', $t->id, absolute: false),
            default => $this->task->assignee_id ? route('users.show', $this->task->assignee_id, absolute: false) : null,
        };

        return [
            'type' => 'department_task_overdue',
            'title' => 'Просрочка в вашем отделе',
            'message' => "{$this->assigneeName}: {$this->task->title}",
            'task_id' => $this->task->id,
            'url' => $url,
        ];
    }
}
