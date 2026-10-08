<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Filament\Resources\TaskResource;
use App\Models\Task;
use App\Services\TaskAccessService;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $modelLabel = 'tarefa';

    protected static ?string $pluralModelLabel = 'tarefas';

    protected static ?string $title = 'Tarefas';

    public function form(Form $form): Form
    {
        return TaskResource::form($form);
    }

    protected function canDeleteAny(): bool
    {
        return auth()->user()->can('update', $this->getOwnerRecord()) && auth()->user()->can('delete_task');
    }

    protected function canCreate(): bool
    {
        return auth()->user()->can('create', [Task::class, $this->getOwnerRecord()]);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->visibleTo(auth()->user()))
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Título'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->fillForm(fn (): array => ['project_id' => $this->getOwnerRecord()->id, 'status' => 'backlog', 'priority' => 'medium'])
                    ->using(function (array $data): Task {
                        $data['project_id'] = $this->getOwnerRecord()->id;

                        return app(TaskAccessService::class)->create(auth()->user(), $data);
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->using(function (Task $record, array $data): Task {
                        $data['project_id'] = $this->getOwnerRecord()->id;

                        return app(TaskAccessService::class)->update(auth()->user(), $record, $data);
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->before(function (Collection $records): void {
                        foreach ($records as $record) {
                            Gate::authorize('delete', $record);
                        }
                    }),
                ]),
            ]);
    }
}
