<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\Pages\ListProjects;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class ManagerProjectAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $managerA;

    private User $managerB;

    private Project $projectA;

    private Project $projectB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->managerA = User::factory()->create()->assignRole('manager');
        $this->managerB = User::factory()->create()->assignRole('manager');
        $this->projectA = Project::create([
            'name' => 'Projeto A', 'manager_id' => $this->managerA->id,
            'status' => 'in_progress', 'priority' => 'medium',
        ]);
        $this->projectB = Project::create([
            'name' => 'Projeto B', 'manager_id' => $this->managerB->id,
            'status' => 'in_progress', 'priority' => 'medium',
        ]);

        $this->actingAs($this->managerA);
    }

    public function test_manager_can_view_and_edit_their_own_project(): void
    {
        $this->assertTrue(Gate::allows('view', $this->projectA));
        $this->assertTrue(Gate::allows('update', $this->projectA));

        $this->get(ProjectResource::getUrl('view', ['record' => $this->projectA]))->assertOk();
        $this->get(ProjectResource::getUrl('edit', ['record' => $this->projectA]))->assertOk();

        Livewire::test(EditProject::class, ['record' => $this->projectA->id])
            ->fillForm(['name' => 'Projeto A atualizado'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('projects', [
            'id' => $this->projectA->id,
            'name' => 'Projeto A atualizado',
            'manager_id' => $this->managerA->id,
        ]);
    }

    public function test_manager_cannot_view_edit_or_delete_another_managers_project_even_as_a_member(): void
    {
        foreach (['view', 'update', 'delete', 'manageMembers'] as $ability) {
            $this->assertFalse(Gate::allows($ability, $this->projectB));
        }

        $this->projectB->members()->attach($this->managerA);

        foreach (['view', 'update', 'delete', 'manageMembers'] as $ability) {
            $this->assertFalse(Gate::allows($ability, $this->projectB));
        }
    }

    public function test_direct_urls_do_not_open_another_managers_project(): void
    {
        $this->get(ProjectResource::getUrl('edit', ['record' => $this->projectB]))
            ->assertNotFound()
            ->assertDontSee('wire:submit="save"', escape: false);

        $this->get(ProjectResource::getUrl('view', ['record' => $this->projectB]))->assertNotFound();
        $this->assertDatabaseHas('projects', ['id' => $this->projectB->id, 'name' => 'Projeto B']);
    }

    public function test_project_resource_lists_only_the_managers_own_projects(): void
    {
        $this->projectB->members()->attach($this->managerA);

        $this->assertSame([$this->projectA->id], ProjectResource::getEloquentQuery()->pluck('projects.id')->all());

        Livewire::test(ListProjects::class)
            ->assertCanSeeTableRecords([$this->projectA])
            ->assertCanNotSeeTableRecords([$this->projectB])
            ->assertTableActionVisible('edit', $this->projectA)
            ->assertTableActionHidden('edit', $this->projectB)
            ->assertTableActionHidden('delete', $this->projectB);
    }

    public function test_direct_delete_action_cannot_delete_another_managers_project(): void
    {
        Livewire::test(ListProjects::class)
            ->call('mountTableAction', 'delete', (string) $this->projectB->id)
            ->assertSet('mountedTableActions', [])
            ->call('callMountedTableAction');

        $this->assertModelExists($this->projectB);
        $this->assertDatabaseHas('projects', [
            'id' => $this->projectB->id, 'manager_id' => $this->managerB->id,
        ]);
    }

    public function test_admin_and_member_keep_their_existing_project_access(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $member = User::factory()->create()->assignRole('member');
        $this->projectA->members()->attach($member);

        $this->actingAs($admin);
        foreach ([$this->projectA, $this->projectB] as $project) {
            foreach (['view', 'update', 'delete'] as $ability) {
                $this->assertTrue(Gate::allows($ability, $project));
            }
        }
        Livewire::test(ListProjects::class)->assertCanSeeTableRecords([$this->projectA, $this->projectB]);

        $this->actingAs($member);
        $this->assertTrue(Gate::allows('view', $this->projectA));
        $this->assertFalse(Gate::allows('view', $this->projectB));
        $this->assertFalse(Gate::allows('update', $this->projectA));
        $this->assertFalse(Gate::allows('delete', $this->projectA));
        Livewire::test(ListProjects::class)
            ->assertCanSeeTableRecords([$this->projectA])
            ->assertCanNotSeeTableRecords([$this->projectB]);
    }
}
