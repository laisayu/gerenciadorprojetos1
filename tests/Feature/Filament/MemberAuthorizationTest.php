<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\MyTasks;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\Pages\ListProjects;
use App\Filament\Resources\ProjectResource\RelationManagers\MembersRelationManager;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\RoleResource\Pages\ManageRoles;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Pages\ListTasks;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskAccessService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MemberAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $memberA;

    private User $memberB;

    private Project $projectA;

    private Project $projectB;

    private Task $taskA;

    private Task $taskB;

    private Task $taskC;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->manager = User::factory()->create()->assignRole('manager');
        $this->memberA = User::factory()->create()->assignRole('member');
        $this->memberB = User::factory()->create()->assignRole('member');

        $this->projectA = Project::create([
            'name' => 'Projeto A', 'manager_id' => $this->manager->id,
            'status' => 'in_progress', 'priority' => 'medium',
        ]);
        $this->projectB = Project::create([
            'name' => 'Projeto B', 'manager_id' => $this->manager->id,
            'status' => 'in_progress', 'priority' => 'medium',
        ]);
        $this->projectA->members()->attach([$this->memberA->id, $this->memberB->id]);
        $this->projectB->members()->attach($this->memberB);

        $this->taskA = $this->projectA->tasks()->create([
            'title' => 'Task A', 'responsible_id' => $this->memberA->id,
            'created_by' => $this->manager->id, 'status' => 'todo',
        ]);
        $this->taskB = $this->projectA->tasks()->create([
            'title' => 'Task B', 'responsible_id' => $this->memberB->id,
            'created_by' => $this->manager->id, 'status' => 'todo',
        ]);
        $this->taskC = $this->projectB->tasks()->create([
            'title' => 'Task C', 'responsible_id' => $this->memberA->id,
            'created_by' => $this->manager->id, 'status' => 'todo',
        ]);

        $this->actingAs($this->memberA);
    }

    public function test_member_can_view_the_project_they_belong_to(): void
    {
        $this->assertTrue(Gate::allows('view', $this->projectA));
        $this->get(ProjectResource::getUrl('view', ['record' => $this->projectA]))
            ->assertOk()->assertSee($this->projectA->name);
    }

    public function test_member_cannot_edit_the_project_they_belong_to(): void
    {
        $this->assertFalse(Gate::allows('update', $this->projectA));
        $this->get(ProjectResource::getUrl('edit', ['record' => $this->projectA]))->assertForbidden();
        Livewire::test(EditProject::class, ['record' => $this->projectA->id])->assertForbidden();
        $this->assertDatabaseHas('projects', ['id' => $this->projectA->id, 'name' => 'Projeto A']);
    }

    public function test_member_cannot_delete_the_project_they_belong_to(): void
    {
        $this->assertFalse(Gate::allows('delete', $this->projectA));

        Livewire::test(ListProjects::class)
            ->assertTableActionHidden('delete', $this->projectA)
            ->call('mountTableAction', 'delete', (string) $this->projectA->id)
            ->assertSet('mountedTableActions', [])
            ->call('callMountedTableAction');

        $this->assertModelExists($this->projectA);
    }

    public function test_member_cannot_attach_project_members(): void
    {
        $this->assertFalse(Gate::allows('manageMembers', $this->projectA));

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $this->projectA, 'pageClass' => EditProject::class,
        ])
            ->assertTableActionHidden('attach')
            ->call('mountTableAction', 'attach')
            ->assertSet('mountedTableActions', [])
            ->set('mountedTableActionsData', [['recordId' => $this->manager->id]])
            ->call('callMountedTableAction');

        $this->assertEqualsCanonicalizing(
            [$this->memberA->id, $this->memberB->id],
            $this->projectA->members()->pluck('users.id')->all(),
        );
    }

    public function test_member_cannot_detach_project_members(): void
    {
        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $this->projectA, 'pageClass' => EditProject::class,
        ])
            ->assertTableActionHidden('detach', $this->memberB)
            ->call('mountTableAction', 'detach', (string) $this->memberB->id)
            ->assertSet('mountedTableActions', [])
            ->call('callMountedTableAction');

        $this->assertEqualsCanonicalizing(
            [$this->memberA->id, $this->memberB->id],
            $this->projectA->members()->pluck('users.id')->all(),
        );
    }

    public function test_member_cannot_access_another_project_by_direct_url(): void
    {
        $this->assertFalse(Gate::allows('view', $this->projectB));
        foreach (['view', 'edit'] as $page) {
            $this->get(ProjectResource::getUrl($page, ['record' => $this->projectB]))->assertNotFound();
        }
    }

    public function test_project_resource_lists_only_the_members_project(): void
    {
        $this->assertSame([$this->projectA->id], ProjectResource::getEloquentQuery()->pluck('projects.id')->all());

        Livewire::test(ListProjects::class)
            ->assertCanSeeTableRecords([$this->projectA])
            ->assertCanNotSeeTableRecords([$this->projectB])
            ->assertTableActionHidden('edit', $this->projectA);
    }

    public function test_member_can_view_their_own_task(): void
    {
        $this->assertTrue(Gate::allows('view', $this->taskA));
        $this->get(TaskResource::getUrl('view', ['record' => $this->taskA]))
            ->assertOk()->assertSee($this->taskA->title);
    }

    public function test_member_can_change_only_the_status_of_their_own_task(): void
    {
        $this->assertTrue(Gate::allows('updateStatus', $this->taskA));
        $this->assertFalse(Gate::allows('update', $this->taskA));
        $this->get(TaskResource::getUrl('edit', ['record' => $this->taskA]))->assertForbidden();

        Livewire::test(ListTasks::class)
            ->assertTableActionHidden('edit', $this->taskA)
            ->assertTableActionVisible('updateStatus', $this->taskA)
            ->callTableAction('updateStatus', $this->taskA, data: [
                'status' => 'in_progress', 'title' => 'Título adulterado',
                'responsible_id' => $this->memberB->id, 'project_id' => $this->projectB->id,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('tasks', [
            'id' => $this->taskA->id, 'status' => 'in_progress', 'title' => 'Task A',
            'responsible_id' => $this->memberA->id, 'project_id' => $this->projectA->id,
        ]);
        $this->assertDatabaseHas('tasks', ['id' => $this->taskB->id, 'status' => 'todo']);
        $this->assertDatabaseHas('tasks', ['id' => $this->taskC->id, 'status' => 'todo']);
    }

    #[DataProvider('taskRecords')]
    public function test_member_cannot_delete_tasks(string $taskProperty): void
    {
        $task = $this->{$taskProperty};
        $this->assertFalse(Gate::allows('delete', $task));

        Livewire::test(ListTasks::class)
            ->assertTableActionHidden('delete', $task)
            ->call('mountTableAction', 'delete', (string) $task->id)
            ->assertSet('mountedTableActions', [])
            ->call('callMountedTableAction');

        $this->assertModelExists($task);
    }

    #[DataProvider('inaccessibleTaskRecords')]
    public function test_member_cannot_access_other_tasks_by_direct_url(string $taskProperty): void
    {
        $task = $this->{$taskProperty};
        foreach (['view', 'update', 'updateStatus'] as $ability) {
            $this->assertFalse(Gate::allows($ability, $task));
        }
        foreach (['view', 'edit'] as $page) {
            $this->get(TaskResource::getUrl($page, ['record' => $task]))->assertNotFound();
        }
    }

    #[DataProvider('inaccessibleTaskRecords')]
    public function test_member_cannot_invoke_status_actions_for_other_tasks(string $taskProperty): void
    {
        $task = $this->{$taskProperty};
        $component = Livewire::test(ListTasks::class);
        foreach (['updateStatus', 'start', 'sendToReview', 'complete', 'reopen'] as $action) {
            $component->call('mountTableAction', $action, (string) $task->id)
                ->assertSet('mountedTableActions', [])
                ->set('mountedTableActionsData', [['status' => 'completed']])
                ->call('callMountedTableAction');
        }

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'todo', 'completed_at' => null]);
    }

    #[DataProvider('inaccessibleTaskRecords')]
    public function test_status_service_rejects_other_tasks(string $taskProperty): void
    {
        $task = $this->{$taskProperty};
        $this->expectException(AuthorizationException::class);

        try {
            app(TaskAccessService::class)->updateStatus($this->memberA, $task, 'completed');
        } finally {
            $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'todo', 'completed_at' => null]);
        }
    }

    #[DataProvider('taskRecords')]
    public function test_update_service_rejects_full_task_edits(string $taskProperty): void
    {
        $task = $this->{$taskProperty};
        $this->expectException(AuthorizationException::class);

        try {
            app(TaskAccessService::class)->update($this->memberA, $task, ['title' => 'Título adulterado']);
        } finally {
            $this->assertDatabaseHas('tasks', ['id' => $task->id, 'title' => $task->title]);
        }
    }

    public function test_task_resource_lists_only_the_members_accessible_task(): void
    {
        $this->assertSame([$this->taskA->id], TaskResource::getEloquentQuery()->pluck('tasks.id')->all());

        Livewire::test(ListTasks::class)
            ->assertCanSeeTableRecords([$this->taskA])
            ->assertCanNotSeeTableRecords([$this->taskB, $this->taskC]);
    }

    public function test_my_tasks_shows_only_task_a(): void
    {
        $this->get(MyTasks::getUrl())->assertOk()
            ->assertSee($this->taskA->title)
            ->assertDontSee($this->taskB->title)
            ->assertDontSee($this->taskC->title);

        $component = Livewire::test(MyTasks::class)
            ->assertSee($this->taskA->title)
            ->assertDontSee($this->taskB->title)
            ->assertDontSee($this->taskC->title);

        $this->assertSame([$this->taskA->id], $component->instance()->getTasks()->modelKeys());

        $component->set('filter', 'todo')
            ->assertSee($this->taskA->title)
            ->assertDontSee($this->taskB->title)
            ->assertDontSee($this->taskC->title);
    }

    public function test_member_cannot_access_user_management(): void
    {
        $this->assertFalse(Gate::allows('viewAny', User::class));
        $this->assertFalse(UserResource::canAccess());
        $this->get(UserResource::getUrl())->assertForbidden();
        $this->get(UserResource::getUrl('create'))->assertForbidden();
        $this->get(UserResource::getUrl('edit', ['record' => $this->memberB]))->assertForbidden();
    }

    public function test_member_cannot_access_roles_or_shield(): void
    {
        $this->assertFalse(Gate::allows('manage_roles'));
        $this->assertFalse(Gate::allows('viewAny', Role::class));
        $this->assertFalse(RoleResource::canAccess());
        $this->get(RoleResource::getUrl())->assertForbidden();
        Livewire::test(ManageRoles::class)->assertForbidden();
    }

    public function test_member_cannot_manage_role_permissions(): void
    {
        $memberRole = Role::findByName('member', 'web');
        $permissions = $memberRole->permissions()->pluck('permissions.id')->all();

        $this->assertFalse(Gate::allows('manage_permissions'));
        foreach (Role::all() as $role) {
            $this->assertFalse(Gate::allows('update', $role));
        }

        $this->get(RoleResource::getUrl('index', [
            'tableAction' => 'edit', 'tableActionRecord' => $memberRole->id,
        ]))->assertForbidden();

        $this->assertEqualsCanonicalizing($permissions, $memberRole->permissions()->pluck('permissions.id')->all());
    }

    public function test_member_cannot_assign_the_admin_role_to_themselves(): void
    {
        $this->assertFalse(Gate::allows('update', $this->memberA));
        $this->get(UserResource::getUrl('edit', ['record' => $this->memberA]))->assertForbidden();

        Livewire::test(EditUser::class, [
            'record' => $this->memberA->id,
            'data' => ['name' => $this->memberA->name, 'email' => $this->memberA->email, 'role' => 'admin'],
        ])->assertForbidden();

        $this->assertSame(['member'], $this->memberA->fresh()->getRoleNames()->all());
        $this->assertDatabaseMissing('model_has_roles', [
            'model_type' => $this->memberA->getMorphClass(), 'model_id' => $this->memberA->id,
            'role_id' => Role::findByName('admin', 'web')->id,
        ]);
    }

    /** @return array<string, array{string}> */
    public static function taskRecords(): array
    {
        return ['Task A' => ['taskA'], ...self::inaccessibleTaskRecords()];
    }

    /** @return array<string, array{string}> */
    public static function inaccessibleTaskRecords(): array
    {
        return ['Task B' => ['taskB'], 'Task C' => ['taskC']];
    }
}
