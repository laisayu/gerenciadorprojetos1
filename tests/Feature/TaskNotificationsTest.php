<?php

namespace Tests\Feature;

use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\RelationManagers\TasksRelationManager;
use App\Filament\Resources\TaskResource\Pages\CreateTask;
use App\Filament\Resources\TaskResource\Pages\EditTask;
use App\Filament\Resources\TaskResource\Pages\ListTasks;
use App\Models\Project;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskCompletedNotification;
use App\Services\TaskAccessService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class TaskNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $responsible;

    private User $otherMember;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->manager = User::factory()->create()->assignRole('manager');
        $this->responsible = User::factory()->create()->assignRole('member');
        $this->otherMember = User::factory()->create()->assignRole('member');
        $this->project = Project::create([
            'name' => 'Projeto de notificações', 'manager_id' => $this->manager->id,
        ]);
        $this->project->members()->attach([$this->responsible->id, $this->otherMember->id]);
        $this->actingAs($this->manager);
    }

    public function test_create_page_persists_one_assignment_for_only_the_responsible_without_queue_or_mail(): void
    {
        Queue::fake();
        Mail::fake();

        Livewire::test(CreateTask::class)
            ->fillForm($this->taskData())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('tasks', ['title' => 'Entrega', 'responsible_id' => $this->responsible->id]);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertNotification(TaskAssignedNotification::class, 'Nova tarefa atribuída', 'A tarefa "Entrega" foi atribuída a você.', 'info');
        Queue::assertNothingPushed();
        Mail::assertNothingOutgoing();
    }

    public function test_project_relation_creation_persists_one_assignment(): void
    {
        Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $this->project, 'pageClass' => EditProject::class,
        ])
            ->callTableAction('create', data: $this->taskData())
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', [
            'type' => TaskAssignedNotification::class, 'notifiable_id' => $this->responsible->id,
        ]);
    }

    public function test_edit_page_notifies_completion_once_and_does_not_repeat_on_another_save(): void
    {
        $task = $this->project->tasks()->create($this->taskData());
        Queue::fake();
        Mail::fake();

        Livewire::test(EditTask::class, ['record' => $task->id])
            ->fillForm(['status' => 'completed'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->fillForm(['description' => 'Descrição atualizada'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'completed']);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertNotification(TaskCompletedNotification::class, 'Tarefa concluída', 'A tarefa "Entrega" foi concluída.', 'success');
        Queue::assertNothingPushed();
        Mail::assertNothingOutgoing();
    }

    public function test_completion_action_persists_one_persistent_success_notification(): void
    {
        $task = $this->project->tasks()->create($this->taskData());

        Livewire::test(ListTasks::class)
            ->callTableAction('complete', $task)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'completed']);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertNotification(TaskCompletedNotification::class, 'Tarefa concluída', 'A tarefa "Entrega" foi concluída.', 'success');
    }

    public function test_project_relation_edit_notifies_completion(): void
    {
        $task = $this->project->tasks()->create($this->taskData());

        Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $this->project, 'pageClass' => EditProject::class,
        ])
            ->callTableAction('edit', $task, data: ['status' => 'completed'])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'completed']);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('notifications', [
            'type' => TaskCompletedNotification::class, 'notifiable_id' => $this->responsible->id,
        ]);
    }

    public function test_completion_notifies_the_current_responsible_even_when_the_old_relation_was_loaded(): void
    {
        $task = $this->project->tasks()->create($this->taskData());
        $task->load('responsible');

        app(TaskAccessService::class)->update($this->manager, $task, [
            'responsible_id' => $this->otherMember->id, 'status' => 'completed',
        ]);

        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('notifications', [
            'type' => TaskCompletedNotification::class, 'notifiable_id' => $this->otherMember->id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'type' => TaskCompletedNotification::class, 'notifiable_id' => $this->responsible->id,
        ]);
    }

    public function test_repeated_status_request_with_a_stale_task_does_not_duplicate_completion(): void
    {
        $task = $this->project->tasks()->create($this->taskData());
        $staleTask = $task->fresh();

        app(TaskAccessService::class)->updateStatus($this->manager, $task, 'completed');
        app(TaskAccessService::class)->updateStatus($this->manager, $staleTask, 'completed');

        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_reopening_and_completing_again_sends_one_notification_for_each_transition(): void
    {
        $task = $this->project->tasks()->create($this->taskData());

        app(TaskAccessService::class)->updateStatus($this->manager, $task, 'completed');
        app(TaskAccessService::class)->updateStatus($this->manager, $task, 'todo');
        $this->assertDatabaseCount('notifications', 2);
        app(TaskAccessService::class)->updateStatus($this->manager, $task, 'completed');

        $this->assertDatabaseCount('notifications', 3);
        $this->assertCount(2, $this->responsible->notifications()->where('type', TaskCompletedNotification::class)->get());
    }

    public function test_repeated_edit_with_a_stale_task_does_not_duplicate_completion(): void
    {
        $task = $this->project->tasks()->create($this->taskData());
        $staleTask = $task->fresh();

        app(TaskAccessService::class)->update($this->manager, $task, ['status' => 'completed']);
        app(TaskAccessService::class)->update($this->manager, $staleTask, ['status' => 'completed']);

        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_creation_rolls_back_task_and_notification_when_delivery_throws(): void
    {
        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            throw new RuntimeException('Falha de notificação');
        });

        try {
            app(TaskAccessService::class)->create($this->manager, $this->taskData());
            $this->fail('A failure during notification delivery must abort creation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Falha de notificação', $exception->getMessage());
        }

        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_completion_rolls_back_status_and_notification_when_delivery_throws(): void
    {
        $task = $this->project->tasks()->create($this->taskData());
        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            throw new RuntimeException('Falha de notificação');
        });

        try {
            app(TaskAccessService::class)->updateStatus($this->manager, $task, 'completed');
            $this->fail('A failure during notification delivery must abort completion.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Falha de notificação', $exception->getMessage());
        }

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'review', 'completed_at' => null]);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_rejected_completion_does_not_persist_a_completion_notification(): void
    {
        $task = $this->project->tasks()->create($this->taskData());
        $task->subtasks()->create(['title' => 'Item pendente', 'is_completed' => false]);

        Livewire::test(EditTask::class, ['record' => $task->id])
            ->fillForm(['status' => 'completed'])
            ->call('save')
            ->assertHasFormErrors(['status']);

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'review']);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_bell_shows_only_the_recipients_notifications_and_keeps_read_notifications(): void
    {
        $task = $this->project->tasks()->create($this->taskData());
        app(TaskAccessService::class)->updateStatus($this->manager, $task, 'completed');
        $this->actingAs($this->responsible);

        $bell = Livewire::test(DatabaseNotifications::class)
            ->assertSee('Nova tarefa atribuída')
            ->assertSee('Tarefa concluída');
        $this->assertSame(2, $bell->instance()->getUnreadNotificationsCount());
        $bell->call('markAllNotificationsAsRead')
            ->call('$refresh')
            ->assertSee('Nova tarefa atribuída')
            ->assertSee('Tarefa concluída');
        $this->assertSame(0, $bell->instance()->getUnreadNotificationsCount());
        $this->assertDatabaseCount('notifications', 2);

        $this->actingAs($this->otherMember);
        Livewire::test(DatabaseNotifications::class)
            ->assertDontSee('Nova tarefa atribuída')
            ->assertDontSee('Tarefa concluída');
        $this->actingAs($this->manager);
        Livewire::test(DatabaseNotifications::class)
            ->assertDontSee('Nova tarefa atribuída')
            ->assertDontSee('Tarefa concluída');
    }

    /** @return array{title: string, project_id: int, responsible_id: int, created_by: int, status: string} */
    private function taskData(): array
    {
        return [
            'title' => 'Entrega', 'project_id' => $this->project->id,
            'responsible_id' => $this->responsible->id, 'created_by' => $this->manager->id,
            'status' => 'review',
        ];
    }

    private function assertNotification(string $type, string $title, string $body, string $status): void
    {
        $notification = $this->responsible->notifications()->where('type', $type)->sole();

        $this->assertSame($this->responsible->getMorphClass(), $notification->notifiable_type);
        $this->assertSame('filament', $notification->data['format']);
        $this->assertNull($notification->read_at);

        $renderedNotification = Notification::fromDatabase($notification);
        $this->assertSame($title, $renderedNotification->getTitle());
        $this->assertSame($body, $renderedNotification->getBody());
        $this->assertSame($status, $renderedNotification->getStatus());
        $this->assertSame('persistent', $renderedNotification->getDuration());
    }
}
