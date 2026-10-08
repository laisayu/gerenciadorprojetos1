<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator() || $user->can('view_any_task');
    }

    public function view(User $user, Task $task): bool
    {
        return Task::query()->visibleTo($user)->whereKey($task->id)->exists();
    }

    public function create(User $user, ?Project $project = null): bool
    {
        return $user->isAdministrator() || ($user->hasRole('manager') && $user->can('create_task') && ($project === null || $project->manager_id === $user->id));
    }

    public function update(User $user, Task $task): bool
    {
        return $this->manage($user, $task, 'update_task');
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->manage($user, $task, 'delete_task');
    }

    public function updateStatus(User $user, Task $task): bool
    {
        return $this->update($user, $task) || ($user->hasRole('member') && ! $user->hasRole('manager') && $user->can('update_own_task_status') && $task->responsible_id === $user->id && $this->view($user, $task));
    }

    public function comment(User $user, Task $task): bool
    {
        return $user->isAdministrator() || ($user->can('comment_task') && $this->view($user, $task));
    }

    private function manage(User $user, Task $task, string $permission): bool
    {
        return $user->isAdministrator() || ($user->hasRole('manager') && $user->can($permission) && $task->project()->where('manager_id', $user->id)->exists());
    }
}
