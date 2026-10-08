<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages\ManageRoles;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleResource extends \BezhanSalleh\FilamentShield\Resources\RoleResource
{
    private const PERMISSION_LABELS = [
        'view_any_project' => 'Listar projetos',
        'view_project' => 'Visualizar projeto',
        'create_project' => 'Criar projeto',
        'update_project' => 'Editar projeto',
        'delete_project' => 'Excluir projeto',
        'manage_project_members' => 'Gerenciar membros do projeto',
        'view_any_task' => 'Listar tarefas',
        'view_task' => 'Visualizar tarefa',
        'create_task' => 'Criar tarefa',
        'update_task' => 'Editar tarefa',
        'delete_task' => 'Excluir tarefa',
        'update_own_task_status' => 'Alterar situação das próprias tarefas',
        'comment_task' => 'Comentar tarefa',
        'view_any_user' => 'Listar usuários',
        'view_user' => 'Visualizar usuário',
        'create_user' => 'Criar usuário',
        'update_user' => 'Editar usuário',
        'delete_user' => 'Excluir usuário',
        'manage_roles' => 'Gerenciar perfis',
        'manage_permissions' => 'Gerenciar permissões',
    ];

    public static function getModelLabel(): string
    {
        return 'perfil';
    }

    public static function getPluralModelLabel(): string
    {
        return 'perfis';
    }

    public static function getNavigationLabel(): string
    {
        return 'Perfis';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Controle de acesso';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->label('Perfil')->disabled()->dehydrated(false)
                ->formatStateUsing(fn (string $state): string => UserResource::ROLE_LABELS[$state] ?? $state),
            CheckboxList::make('permissions')->label('Permissões')
                ->options(fn () => Permission::where('guard_name', 'web')->pluck('name', 'id')
                    ->map(fn (string $name): string => self::PERMISSION_LABELS[$name] ?? $name))->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->recordTitle(fn (Role $record): string => UserResource::ROLE_LABELS[$record->name] ?? $record->name)->columns([
            Tables\Columns\TextColumn::make('name')->label('Perfil')
                ->formatStateUsing(fn (string $state): string => UserResource::ROLE_LABELS[$state] ?? $state),
            Tables\Columns\TextColumn::make('permissions_count')->counts('permissions')->label('Permissões'),
        ])->actions([
            Tables\Actions\EditAction::make()
                ->mutateRecordDataUsing(function (array $data, Role $record): array {
                    $data['permissions'] = $record->permissions()->pluck('permissions.id')->all();

                    return $data;
                })
                ->using(function (Role $record, array $data): Role {
                    Gate::authorize('update', $record);
                    $permissions = Permission::where('guard_name', 'web')->whereIn('id', $data['permissions'] ?? [])->get();
                    if ($record->name === 'admin') {
                        $permissions = Permission::where('guard_name', 'web')->get();
                    }
                    $record->syncPermissions($permissions);

                    return $record;
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRoles::route('/')];
    }
}
