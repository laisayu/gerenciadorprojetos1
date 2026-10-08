<?php

namespace Tests\Feature;

use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\TaskResource\Pages\CreateTask;
use App\Filament\Resources\TaskResource\Pages\EditTask;
use App\Filament\Resources\TaskResource\Pages\ListTasks;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->user = User::factory()->create()->assignRole('manager');
        $this->actingAs($this->user);
        $this->project = Project::create([
            'name' => 'Projeto com prazo', 'manager_id' => $this->user->id,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
        ]);
        $this->project->members()->attach($this->user);
    }

    #[DataProvider('outsideDates')]
    public function test_create_form_rejects_delivery_outside_project_dates(string $date): void
    {
        Livewire::test(CreateTask::class)
            ->fillForm([
                'title' => 'Entrega', 'project_id' => $this->project->id,
                'responsible_id' => $this->user->id, 'created_by' => $this->user->id,
                'due_date' => $date,
            ])
            ->call('create')
            ->assertHasFormErrors(['due_date']);

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_edit_form_rejects_delivery_after_project_end(): void
    {
        $task = $this->createTask();

        Livewire::test(EditTask::class, ['record' => $task->id])
            ->fillForm(['due_date' => '2026-11-01'])
            ->call('save')
            ->assertHasFormErrors(['due_date']);

        $this->assertSame('2026-10-31', $task->fresh()->due_date->toDateString());
    }

    public function test_changing_project_revalidates_the_delivery_date(): void
    {
        $task = $this->createTask();
        $otherProject = Project::create([
            'name' => 'Prazo menor', 'manager_id' => $this->user->id,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-15',
        ]);
        $otherProject->members()->attach($this->user);

        Livewire::test(EditTask::class, ['record' => $task->id])
            ->fillForm(['project_id' => $otherProject->id])
            ->call('save')
            ->assertHasFormErrors(['due_date']);

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'project_id' => $this->project->id]);
    }

    #[DataProvider('boundaryDates')]
    public function test_delivery_on_project_boundary_can_be_created_and_saved_again(string $date): void
    {
        Livewire::test(CreateTask::class)
            ->fillForm([
                'title' => 'Entrega', 'project_id' => $this->project->id,
                'responsible_id' => $this->user->id, 'created_by' => $this->user->id,
                'due_date' => $date,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $task = Task::query()->sole();
        Livewire::test(EditTask::class, ['record' => $task->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($date, $task->fresh()->due_date->toDateString());
    }

    public function test_model_rejects_delivery_after_project_end(): void
    {
        $task = $this->createTask();

        try {
            $task->update(['due_date' => '2026-11-01']);
            $this->fail('A delivery after the project end was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('due_date', $exception->errors());
        }

        $this->assertSame('2026-10-31', $task->fresh()->due_date->toDateString());
    }

    public function test_project_end_cannot_be_shortened_before_an_existing_delivery(): void
    {
        $this->createTask();

        Livewire::test(EditProject::class, ['record' => $this->project->id])
            ->fillForm(['end_date' => '2026-10-30'])
            ->call('save')
            ->assertHasFormErrors(['end_date']);

        $this->assertSame('2026-10-31', $this->project->fresh()->end_date->toDateString());
    }

    public function test_completion_action_rejects_a_pending_checklist(): void
    {
        $task = $this->createTask();
        $task->subtasks()->create(['title' => 'Revisar entrega', 'is_completed' => false]);
        $task->subtasks()->create(['title' => 'Preparar entrega', 'is_completed' => true]);

        Livewire::test(ListTasks::class)
            ->callTableAction('complete', $task)
            ->assertTableColumnStateSet('status', 'review', $task->id)
            ->assertSee('Em revisão')
            ->assertDontSee('Reabrir');

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'review', 'completed_at' => null]);
        Notification::assertNotified(
            Notification::make()->title('Não é possível concluir a tarefa')
                ->body('Conclua todos os itens da lista de verificação antes de concluir a tarefa.')
                ->danger()
                ->persistent(),
        );
    }

    public function test_edit_form_cannot_bypass_the_checklist_by_changing_status(): void
    {
        $task = $this->createTask();
        $task->subtasks()->create(['title' => 'Revisar entrega', 'is_completed' => false]);

        Livewire::test(EditTask::class, ['record' => $task->id])
            ->fillForm(['status' => 'completed'])
            ->call('save')
            ->assertHasFormErrors(['status'])
            ->assertSee('Conclua todos os itens da lista de verificação antes de concluir a tarefa.');

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'review', 'completed_at' => null]);
    }

    public function test_completion_dialog_explains_pending_checklist_before_confirmation(): void
    {
        $task = $this->createTask();
        $task->subtasks()->create(['title' => 'Revisar entrega', 'is_completed' => false]);

        $component = Livewire::test(ListTasks::class)
            ->mountTableAction('complete', $task)
            ->assertSee(Task::COMPLETION_BLOCKED_MESSAGE);

        $this->assertNull($component->instance()->getMountedTableAction()->getModalSubmitAction());
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'review']);
    }

    public function test_list_warns_about_a_previously_completed_task_with_pending_items(): void
    {
        $task = $this->createTask();
        $task->update(['status' => 'completed']);
        $task->subtasks()->create(['title' => 'Item adicionado depois', 'is_completed' => false]);

        Livewire::test(ListTasks::class)
            ->assertSee('Lista de verificação pendente: reabra a tarefa para corrigir.')
            ->callTableAction('reopen', $task)
            ->assertDontSee('Lista de verificação pendente: reabra a tarefa para corrigir.');

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'todo', 'completed_at' => null]);
    }

    public function test_checklist_is_rechecked_when_confirming_an_already_open_dialog(): void
    {
        $task = $this->createTask();
        $item = $task->subtasks()->create(['title' => 'Revisar entrega', 'is_completed' => true]);
        $component = Livewire::test(ListTasks::class)->mountTableAction('complete', $task);
        $this->assertNotNull($component->instance()->getMountedTableAction()->getModalSubmitAction());

        $item->update(['is_completed' => false]);

        $component->callMountedTableAction()
            ->assertSee('Em revisão')
            ->assertDontSee('Reabrir');

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'review', 'completed_at' => null]);
        Notification::assertNotified('Não é possível concluir a tarefa');
    }

    public function test_completion_checks_fresh_checklist_items(): void
    {
        $task = $this->createTask();
        $task->load('subtasks');
        $task->subtasks()->create(['title' => 'Revisar entrega', 'is_completed' => false]);

        try {
            $task->update(['status' => 'completed']);
            $this->fail('A task with pending checklist items was completed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'review']);
    }

    public function test_completion_action_succeeds_after_all_items_are_checked(): void
    {
        $task = $this->createTask();
        $item = $task->subtasks()->create(['title' => 'Revisar entrega', 'is_completed' => false]);
        $item->update(['is_completed' => true]);

        Livewire::test(ListTasks::class)->callTableAction('complete', $task);

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'completed']);
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_task_without_checklist_can_be_completed(): void
    {
        $task = $this->createTask();

        Livewire::test(EditTask::class, ['record' => $task->id])
            ->fillForm(['status' => 'completed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'completed']);
    }

    /** @return array<string, array{string}> */
    public static function outsideDates(): array
    {
        return ['before start' => ['2026-09-30'], 'after end' => ['2026-11-01']];
    }

    /** @return array<string, array{string}> */
    public static function boundaryDates(): array
    {
        return ['start' => ['2026-10-01'], 'end' => ['2026-10-31']];
    }

    private function createTask(): Task
    {
        return $this->project->tasks()->create([
            'title' => 'Entrega', 'responsible_id' => $this->user->id,
            'created_by' => $this->user->id, 'status' => 'review',
            'due_date' => '2026-10-31',
        ]);
    }
}
