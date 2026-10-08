<?php

namespace App\Policies;

use App\Models\TaskComment;
use App\Models\User;

class TaskCommentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_task');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, TaskComment $record): bool
    {
        return $user->can('update', $record->task);
    }

    public function delete(User $user, TaskComment $record): bool
    {
        return $this->update($user, $record);
    }
}
