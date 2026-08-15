<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskOverdue extends Notification
{
    use Queueable;

    public function __construct(public Task $task) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        // Задача живёт на карточке сделки/заказа; без привязки — в профиле
        // исполнителя (блок «Задачи»).
        $t = $this->task->taskable;
        $url = match (true) {
            $t instanceof \App\Models\Project => route('projects.show', $t->id, absolute: false),
            $t !== null => route('deals.show', $t->id, absolute: false),
            default => $this->task->assignee_id ? route('users.show', $this->task->assignee_id, absolute: false) : null,
        };

        return [
            'type' => 'task_overdue',
            'title' => 'Задача просрочена',
            'message' => $this->task->title,
            'task_id' => $this->task->id,
            'url' => $url,
        ];
    }
}
