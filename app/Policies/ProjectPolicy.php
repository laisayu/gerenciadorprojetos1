<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator() || $user->can('view_any_project');
    }

    public function view(User $user, Project $project): bool
    {
        return Project::query()->visibleTo($user)->whereKey($project->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->isAdministrator() || ($user->hasRole('manager') && $user->can('create_project'));
    }

    public function update(User $user, Project $project): bool
    {
        return $this->manage($user, $project, 'update_project');
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->manage($user, $project, 'delete_project');
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $this->manage($user, $project, 'manage_project_members');
    }

    private function manage(User $user, Project $project, string $permission): bool
    {
        return $user->isAdministrator() || ($user->hasRole('manager') && $user->can($permission) && $project->manager_id === $user->id);
    }
}
