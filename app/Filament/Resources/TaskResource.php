<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TaskResource\Pages;
use App\Filament\Resources\TaskResource\RelationManagers;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskAccessService;
use Filament\Actions\StaticAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class TaskResource extends Resource
{
    public const STATUS_LABELS = [
        'backlog' => 'Não iniciada',
        'todo' => 'A fazer',
        'in_progress' => 'Em andamento',
        'review' => 'Em revisão',
        'completed' => 'Concluída',
        'cancelled' => 'Cancelada',
    ];

    public const PRIORITY_LABELS = [
        'low' => 'Baixa',
        'medium' => 'Média',
        'high' => 'Alta',
        'urgent' => 'Urgente',
    ];

    protected static ?string $model = Task::class;

    protected static ?string $modelLabel = 'tarefa';

    protected static ?string $pluralModelLabel = 'tarefas';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('title')->label('Título'),
            TextEntry::make('description')->label('Descrição'),
            TextEntry::make('project.name')->label('Projeto'),
            TextEntry::make('responsible.name')->label('Responsável'),
            TextEntry::make('creator.name')->label('Criado por'),
            TextEntry::make('status')->label('Situação')->badge()
                ->formatStateUsing(fn (string $state): string => self::STATUS_LABELS[$state] ?? $state),
            TextEntry::make('priority')->label('Prioridade')->badge()
                ->formatStateUsing(fn (string $state): string => self::PRIORITY_LABELS[$state] ?? $state),
            TextEntry::make('start_date')->label('Início')->date('d/m/Y'),
            TextEntry::make('due_date')->label('Prazo')->date('d/m/Y'),
            TextEntry::make('completed_at')->label('Concluída em')->dateTime('d/m/Y H:i'),
            TextEntry::make('estimated_hours')->label('Horas estimadas'),
            RepeatableEntry::make('attachments')->label('Anexos')
                ->state(fn (Task $record) => $record->getMedia('attachments'))
                ->schema([
                    TextEntry::make('file_name')->label('Arquivo')
                        ->url(fn (Media $record): string => $record->getUrl())
                        ->openUrlInNewTab(),
                ]),
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('title')
                    ->label('Título')
                    ->required(),

                Textarea::make('description')
                    ->label('Descrição'),

                Select::make('project_id')
                    ->label('Projeto')
                    ->options(fn (?Task $record): array => Project::query()->manageableBy(auth()->user())
                        ->where(function (Builder $query) use ($record): void {
                            $query->whereNotIn('status', ['completed', 'cancelled']);
                            if ($record) {
                                $query->orWhereKey($record->project_id);
                            }
                        })->pluck('name', 'id')->all())
                    ->live()
                    ->required(),

                Select::make('responsible_id')
                    ->label('Responsável')
                    ->options(function (Get $get) {
                        $projectId = $get('project_id');

                        if (! $projectId) {
                            return [];
                        }

                        return Project::query()->manageableBy(auth()->user())->find($projectId)
                            ?->members()
                            ->pluck('name', 'users.id')
                            ->toArray() ?? [];
                    })
                    ->searchable()
                    ->required(),

                TextInput::make('creator.name')->label('Criado por')->disabled()->dehydrated(false)->visibleOn('view'),

                Select::make('priority')
                    ->label('Prioridade')
                    ->options(self::PRIORITY_LABELS)
                    ->default('medium')
                    ->required(),

                Select::make('status')
                    ->label('Situação')
                    ->options(self::STATUS_LABELS)
                    ->default('backlog')
                    ->required(),

                DatePicker::make('start_date')
                    ->label('Data de início'),

                DatePicker::make('due_date')
                    ->label('Data limite')
                    ->minDate(function (Get $get) {
                        $project = Project::query()->visibleTo(auth()->user())->find($get('project_id'));

                        return $project?->start_date;
                    })
                    ->maxDate(function (Get $get) {
                        $project = Project::query()->visibleTo(auth()->user())->find($get('project_id'));

                        return $project?->end_date;
                    }),

                DateTimePicker::make('completed_at')
                    ->label('Data de conclusão'),

                TextInput::make('estimated_hours')
                    ->label('Estimativa de horas')
                    ->numeric(),

                SpatieMediaLibraryFileUpload::make('attachments')
                    ->label('Anexos')
                    ->collection('attachments')
                    ->multiple()
                    ->downloadable()
                    ->openable()
                    ->reorderable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('project.name')
                    ->label('Projeto')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Prazo')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('deadline_status')
                    ->label('Situação do prazo')
                    ->state(function (Task $record): string {
                        if (! $record->due_date) {
                            return 'Sem prazo';
                        }

                        if (in_array($record->status, ['completed', 'cancelled'])) {
                            return 'Finalizada';
                        }

                        if ($record->due_date < now()->toDateString()) {
                            return 'Atrasada';
                        }

                        return 'No prazo';
                    })
                    ->badge()
                    ->color(function (string $state): string {
                        return match ($state) {
                            'Atrasada' => 'danger',
                            'No prazo' => 'success',
                            'Finalizada' => 'gray',
                            'Sem prazo' => 'gray',
                            default => 'gray',
                        };
                    }),

                TextColumn::make('responsible.name')
                    ->label('Responsável')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->description(fn (Task $record): ?string => $record->status === 'completed' && $record->hasPendingSubtasks()
                        ? 'Lista de verificação pendente: reabra a tarefa para corrigir.'
                        : null)

                    ->formatStateUsing(fn (string $state): string => self::STATUS_LABELS[$state] ?? $state),

                TextColumn::make('priority')
                    ->label('Prioridade')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::PRIORITY_LABELS[$state] ?? $state),

                TextColumn::make('estimated_hours')
                    ->label('Horas estimadas'),
            ])

            ->filters([
                SelectFilter::make('project_id')
                    ->label('Projeto')
                    ->relationship('project', 'name', fn (Builder $query) => $query->visibleTo(auth()->user())),

                SelectFilter::make('responsible_id')
                    ->label('Responsável')
                    ->options(fn () => User::query()->whereHas('assignedTasks', fn ($query) => $query->visibleTo(auth()->user()))->pluck('name', 'id')),

                SelectFilter::make('status')
                    ->label('Situação')
                    ->options(self::STATUS_LABELS),

                SelectFilter::make('priority')
                    ->label('Prioridade')
                    ->options(self::PRIORITY_LABELS),
            ])

            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\Action::make('updateStatus')
                    ->label('Alterar situação')
                    ->authorize('updateStatus')
                    ->form([Select::make('status')->label('Situação')->options(self::STATUS_LABELS)->required()])
                    ->fillForm(fn (Task $record): array => ['status' => $record->status])
                    ->action(fn (Task $record, array $data) => app(TaskAccessService::class)->updateStatus(auth()->user(), $record, $data['status'])),
                Tables\Actions\Action::make('start')
                    ->authorize('updateStatus')
                    ->label('Iniciar')
                    ->visible(fn ($record) => $record->status === 'todo')
                    ->action(function ($record) {
                        app(TaskAccessService::class)->updateStatus(auth()->user(), $record, 'in_progress');
                    }),

                Tables\Actions\Action::make('sendToReview')
                    ->authorize('updateStatus')
                    ->label('Enviar para revisão')
                    ->visible(fn ($record) => $record->status === 'in_progress')
                    ->action(function ($record) {
                        app(TaskAccessService::class)->updateStatus(auth()->user(), $record, 'review');
                    }),

                Tables\Actions\Action::make('complete')
                    ->authorize('updateStatus')
                    ->label('Concluir')
                    ->visible(fn ($record) => $record->status === 'review')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Task $record): string => $record->hasPendingSubtasks()
                        ? 'Não é possível concluir a tarefa'
                        : 'Concluir tarefa')
                    ->modalDescription(fn (Task $record): string => $record->hasPendingSubtasks()
                        ? Task::COMPLETION_BLOCKED_MESSAGE
                        : 'Deseja marcar esta tarefa como concluída?')
                    ->modalSubmitAction(fn (StaticAction $action, Task $record): StaticAction|bool => $record->hasPendingSubtasks() ? false : $action)
                    ->modalSubmitActionLabel('Concluir')
                    ->modalCancelActionLabel(fn (Task $record): string => $record->hasPendingSubtasks() ? 'Fechar' : 'Cancelar')
                    ->action(function (Task $record): void {
                        try {
                            app(TaskAccessService::class)->updateStatus(auth()->user(), $record, 'completed');
                            Notification::make()
                                ->title('Tarefa concluída')
                                ->body('A tarefa "'.$record->title.'" foi concluída com sucesso.')
                                ->success()
                                ->send();
                        } catch (ValidationException $exception) {
                            $record->refresh();

                            Notification::make()
                                ->title('Não é possível concluir a tarefa')
                                ->body($exception->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('reopen')
                    ->authorize('updateStatus')
                    ->label('Reabrir')
                    ->visible(fn ($record) => $record->status === 'completed')
                    ->requiresConfirmation()
                    ->action(function (Task $record): void {
                        try {
                            app(TaskAccessService::class)->updateStatus(auth()->user(), $record, 'todo');
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Não é possível reabrir a tarefa')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\SubtasksRelationManager::class,
            RelationManagers\CommentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTasks::route('/'),
            'create' => Pages\CreateTask::route('/create'),
            'view' => Pages\ViewTask::route('/{record}'),
            'edit' => Pages\EditTask::route('/{record}/edit'),
        ];
    }
}
