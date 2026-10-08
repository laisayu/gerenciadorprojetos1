<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectCompletionTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('pendingStatuses')]
    public function test_model_update_rejects_pending_tasks(string $status): void
    {
        $project = $this->projectWithTasks(['completed', $status, 'cancelled']);

        try {
            $project->update(['status' => 'completed']);
            $this->fail('A project with pending tasks was completed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame('in_progress', $project->fresh()->status);
    }

    #[DataProvider('finishedStatuses')]
    public function test_model_update_allows_only_finished_tasks(array $statuses): void
    {
        $project = $this->projectWithTasks($statuses);

        $project->update(['status' => 'completed']);

        $this->assertSame('completed', $project->fresh()->status);
    }

    #[DataProvider('bypassMethods')]
    public function test_database_rejects_updates_that_bypass_model_events(string $method): void
    {
        $project = $this->projectWithTasks(['backlog']);

        try {
            DB::transaction(function () use ($project, $method): void {
                match ($method) {
                    'quiet' => $project->updateQuietly(['status' => 'completed']),
                    'eloquent' => Project::whereKey($project->id)->update(['status' => 'completed']),
                    'query' => DB::table('projects')->where('id', $project->id)->update(['status' => 'completed']),
                };
            });
            $this->fail('An update bypassed the completion rule.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('projects_completed_tasks_check', $exception->getMessage());
        }

        $this->assertSame('in_progress', $project->fresh()->status);
    }

    public function test_reopening_a_task_cannot_leave_a_completed_project_with_pending_tasks(): void
    {
        $project = $this->projectWithTasks(['completed']);
        $project->update(['status' => 'completed']);

        try {
            $project->tasks()->firstOrFail()->update(['status' => 'todo']);
            $this->fail('A task was reopened on a completed project.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame('completed', $project->tasks()->firstOrFail()->status);
    }

    public function test_pending_tasks_of_another_project_do_not_block_completion(): void
    {
        $this->projectWithTasks(['backlog']);
        $project = $this->projectWithTasks(['completed']);

        $project->complete();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'completed']);
    }

    public function test_completion_queries_fresh_tasks_instead_of_a_loaded_relation(): void
    {
        $project = $this->projectWithTasks(['completed']);
        $project->load('tasks');
        $project->tasks()->firstOrFail()->update(['status' => 'review']);

        $this->expectException(ValidationException::class);

        $project->update(['status' => 'completed']);
    }

    public function test_tasks_can_be_reopened_after_reopening_the_project(): void
    {
        $project = $this->projectWithTasks(['completed']);
        $project->complete();
        $project->update(['status' => 'in_progress']);
        $task = $project->tasks()->firstOrFail();

        $task->update(['status' => 'todo']);

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'todo']);
    }

    #[DataProvider('pendingTaskWrites')]
    public function test_database_rejects_pending_task_writes_on_completed_projects(string $method): void
    {
        $project = $this->projectWithTasks(['completed']);
        $project->complete();
        $task = $project->tasks()->firstOrFail();
        $otherProject = $this->projectWithTasks(['todo']);
        $otherTask = $otherProject->tasks()->firstOrFail();

        try {
            DB::transaction(function () use ($method, $project, $task, $otherTask): void {
                match ($method) {
                    'insert' => DB::table('tasks')->insert([
                        'project_id' => $project->id, 'title' => 'New task',
                        'responsible_id' => $task->responsible_id, 'created_by' => $task->created_by,
                    ]),
                    'reopen' => $task->updateQuietly(['status' => 'todo']),
                    'move' => Task::whereKey($otherTask->id)->update(['project_id' => $project->id]),
                };
            });
            $this->fail('A pending task was written to a completed project.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('tasks_completed_project_check', $exception->getMessage());
        }

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'completed']);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'completed']);
        $this->assertDatabaseHas('tasks', ['id' => $otherTask->id, 'project_id' => $otherProject->id]);
        $this->assertDatabaseCount('tasks', 2);
    }

    public function test_finished_tasks_can_be_added_to_a_completed_project(): void
    {
        $project = $this->projectWithTasks(['completed']);
        $project->complete();
        $task = $project->tasks()->firstOrFail();

        $project->tasks()->create([
            'title' => 'Finished task', 'responsible_id' => $task->responsible_id,
            'created_by' => $task->created_by, 'status' => 'cancelled',
        ]);

        $this->assertDatabaseHas('tasks', ['project_id' => $project->id, 'status' => 'cancelled']);
    }

    public static function pendingTaskWrites(): array
    {
        return ['insert with default backlog' => ['insert'], 'reopen quietly' => ['reopen'], 'move pending task' => ['move']];
    }

    public static function pendingStatuses(): array
    {
        return array_combine(
            ['backlog', 'todo', 'in_progress', 'review', 'unknown'],
            array_map(fn (string $status): array => [$status], ['backlog', 'todo', 'in_progress', 'review', 'unknown']),
        );
    }

    public static function finishedStatuses(): array
    {
        return [
            'completed' => [['completed', 'completed']],
            'cancelled' => [['cancelled', 'cancelled']],
            'mixed' => [['completed', 'cancelled']],
            'empty' => [[]],
        ];
    }

    public static function bypassMethods(): array
    {
        return ['quiet' => ['quiet'], 'eloquent' => ['eloquent'], 'query' => ['query']];
    }

    private function projectWithTasks(array $statuses): Project
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Test project', 'manager_id' => $user->id, 'status' => 'in_progress']);

        foreach ($statuses as $status) {
            $project->tasks()->create([
                'title' => 'Test task', 'responsible_id' => $user->id, 'created_by' => $user->id, 'status' => $status,
            ]);
        }

        return $project;
    }
}
