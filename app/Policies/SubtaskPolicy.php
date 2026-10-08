<?php

namespace App\Policies;

use App\Models\Subtask;
use App\Models\User;

class SubtaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_task');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Subtask $record): bool
    {
        return $user->can('update', $record->task);
    }

    public function delete(User $user, Subtask $record): bool
    {
        return $this->update($user, $record);
    }
}
