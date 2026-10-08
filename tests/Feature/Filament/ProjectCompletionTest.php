<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\Pages\ListProjects;
use App\Filament\Resources\TaskResource\Pages\ListTasks;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_edit_form_shows_a_validation_error_for_pending_tasks(): void
    {
        $project = $this->projectWithTask('backlog');

        Livewire::test(EditProject::class, ['record' => $project->id])
            ->fillForm(['status' => 'completed'])
            ->call('save')
            ->assertHasFormErrors(['status'])
            ->assertSee('O projeto não pode ser concluído enquanto existirem tarefas pendentes.');

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'in_progress']);
        Notification::assertNotNotified();
    }

    public function test_edit_form_can_complete_a_project_with_finished_tasks(): void
    {
        $project = $this->projectWithTask('cancelled');

        Livewire::test(EditProject::class, ['record' => $project->id])
            ->fillForm(['status' => 'completed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'completed']);
    }

    public function test_complete_action_warns_about_pending_tasks(): void
    {
        $project = $this->projectWithTask('review');

        Livewire::test(ListProjects::class)
            ->callTableAction('completeProject', $project);

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'in_progress']);
        Notification::assertNotified(
            Notification::make()
                ->title('Não é possível concluir o projeto')
                ->body('O projeto não pode ser concluído enquanto existirem tarefas pendentes.')
                ->danger(),
        );
        Notification::assertNotNotified('Projeto concluído com sucesso');
    }

    public function test_complete_action_completes_a_project_with_finished_tasks(): void
    {
        $project = $this->projectWithTask('completed');

        Livewire::test(ListProjects::class)
            ->callTableAction('completeProject', $project);

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'completed']);
        Notification::assertNotified('Projeto concluído com sucesso');
    }

    public function test_reopen_action_warns_when_the_project_is_completed(): void
    {
        $project = $this->projectWithTask('completed');
        $project->update(['status' => 'completed']);
        $task = $project->tasks()->firstOrFail();

        Livewire::test(ListTasks::class)->callTableAction('reopen', $task);

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'completed']);
        Notification::assertNotified(
            Notification::make()
                ->title('Não é possível reabrir a tarefa')
                ->body('Reabra o projeto antes de adicionar ou reabrir tarefas pendentes.')
                ->danger(),
        );
    }

    private function projectWithTask(string $status): Project
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);
        $project = Project::create([
            'name' => 'Test project', 'manager_id' => $user->id,
            'status' => 'in_progress', 'priority' => 'medium',
        ]);
        $project->tasks()->create([
            'title' => 'Test task', 'responsible_id' => $user->id,
            'created_by' => $user->id, 'status' => $status,
        ]);

        return $project;
    }
}
