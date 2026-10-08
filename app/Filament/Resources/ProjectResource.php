<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProjectResource\Pages;
use App\Filament\Resources\ProjectResource\RelationManagers;
use App\Models\Project;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $modelLabel = 'projeto';

    protected static ?string $pluralModelLabel = 'projetos';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->label('Nome')
                    ->required(),

                Textarea::make('description')
                    ->label('Descrição'),

                Select::make('manager_id')
                    ->label('Gestor responsável')
                    ->relationship('manager', 'name', fn (Builder $query) => $query->whereHas('roles', fn ($roles) => $roles->whereIn('name', ['admin', 'manager'])))
                    ->default(fn () => auth()->id())
                    ->disabled(fn (): bool => ! auth()->user()->isAdministrator())
                    ->searchable()
                    ->preload()
                    ->required(),

                DatePicker::make('start_date')
                    ->label('Data de início'),

                DatePicker::make('end_date')
                    ->label('Data prevista de término'),

                Select::make('status')
                    ->label('Situação')
                    ->options([
                        'planned' => 'Planejado',
                        'in_progress' => 'Em andamento',
                        'paused' => 'Pausado',
                        'completed' => 'Concluído',
                        'cancelled' => 'Cancelado',
                    ])
                    ->rules([
                        fn (?Project $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            if ($value === 'completed' && $record?->hasPendingTasks()) {
                                $fail(Project::COMPLETION_BLOCKED_MESSAGE);
                            }
                        },
                    ])
                    ->default('planned')
                    ->required(),

                Select::make('priority')
                    ->label('Prioridade')
                    ->options([
                        'low' => 'Baixa',
                        'medium' => 'Média',
                        'high' => 'Alta',
                        'urgent' => 'Urgente',
                    ])
                    ->default('medium')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('manager.name')
                    ->label('Gestor')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'planned' => 'Planejado',
                        'in_progress' => 'Em andamento',
                        'paused' => 'Pausado',
                        'completed' => 'Concluído',
                        'cancelled' => 'Cancelado',
                        default => $state,
                    }),

                TextColumn::make('progress_percentage')
                    ->label('Progresso')
                    ->formatStateUsing(fn ($state) => $state.'%'),

                TextColumn::make('priority')
                    ->label('Prioridade')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'low' => 'Baixa',
                        'medium' => 'Média',
                        'high' => 'Alta',
                        'urgent' => 'Urgente',
                        default => $state,
                    }),

                TextColumn::make('start_date')
                    ->label('Início')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('end_date')
                    ->label('Término previsto')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Situação')
                    ->options([
                        'planned' => 'Planejado',
                        'in_progress' => 'Em andamento',
                        'paused' => 'Pausado',
                        'completed' => 'Concluído',
                        'cancelled' => 'Cancelado',
                    ]),

                SelectFilter::make('priority')
                    ->label('Prioridade')
                    ->options([
                        'low' => 'Baixa',
                        'medium' => 'Média',
                        'high' => 'Alta',
                        'urgent' => 'Urgente',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('completeProject')
                    ->authorize('update')
                    ->label('Concluir projeto')
                    ->visible(fn ($record) => ! in_array(
                        $record->status,
                        ['completed', 'cancelled'],
                        true
                    ))
                    ->requiresConfirmation()
                    ->modalHeading('Concluir projeto')
                    ->modalDescription(
                        'O projeto só poderá ser concluído se não existirem tarefas pendentes.'
                    )
                    ->action(function (Project $record): void {
                        Gate::authorize('update', $record);
                        try {
                            $record->complete();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Não é possível concluir o projeto')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Projeto concluído com sucesso')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\MembersRelationManager::class,
            RelationManagers\TasksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjects::route('/'),
            'create' => Pages\CreateProject::route('/create'),
            'view' => Pages\ViewProject::route('/{record}'),
            'edit' => Pages\EditProject::route('/{record}/edit'),
        ];
    }
}
