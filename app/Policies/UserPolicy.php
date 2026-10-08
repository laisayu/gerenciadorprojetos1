<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function view(User $user, User $record): bool
    {
        return $user->isAdministrator();
    }

    public function create(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function update(User $user, User $record): bool
    {
        return $user->isAdministrator();
    }

    public function delete(User $user, User $record): bool
    {
        return $user->isAdministrator() && $user->id !== $record->id
            && ! $record->managedProjects()->exists()
            && ! $record->assignedTasks()->exists()
            && ! $record->createdTasks()->exists()
            && ! $record->taskComments()->exists();
    }
}
