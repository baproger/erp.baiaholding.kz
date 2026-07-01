<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool { return $user->can('task.viewAny'); }
    public function view(User $user, Task $t): bool { return $user->can('task.view'); }
    public function create(User $user): bool { return $user->can('task.create'); }
    public function update(User $user, Task $t): bool { return $user->can('task.update'); }
    public function delete(User $user, Task $t): bool { return $user->can('task.delete'); }
}
