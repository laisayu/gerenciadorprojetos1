<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TaskAccessService
{
    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): Task
    {
        $project = Project::findOrFail($data['project_id']);

        Gate::forUser($actor)->authorize(
            'create',
            [Task::class, $project]
        );

        $data['created_by'] = $actor->id;

        return DB::transaction(fn (): Task => Task::create($data));
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, Task $task, array $data): Task
    {
        Gate::forUser($actor)->authorize('update', $task);

        if (
            isset($data['project_id']) &&
            (int) $data['project_id'] !== $task->project_id
        ) {
            $project = Project::findOrFail($data['project_id']);

            Gate::forUser($actor)->authorize(
                'create',
                [Task::class, $project]
            );
        }

        unset($data['created_by']);

        return $task->getConnection()->transaction(function () use ($actor, $task, $data): Task {
            $task->project()->lockForUpdate()->firstOrFail();
            $task->refresh();

            Gate::forUser($actor)->authorize('update', $task);

            $task->update($data);

            return $task;
        });
    }

    public function updateStatus(
        User $actor,
        Task $task,
        string $status
    ): void {
        Gate::forUser($actor)->authorize('updateStatus', $task);

        Validator::make(
            ['status' => $status],
            [
                'status' => [
                    'required',
                    Rule::in(Task::STATUSES),
                ],
            ]
        )->validate();

        $task->getConnection()->transaction(
            function () use ($actor, $task, $status): void {
                $task->project()
                    ->lockForUpdate()
                    ->firstOrFail();

                $task->refresh();

                Gate::forUser($actor)->authorize('updateStatus', $task);

                $task->update([
                    'status' => $status,
                    'completed_at' => $status === 'completed' ? now() : null,
                ]);
            }
        );
    }
}
