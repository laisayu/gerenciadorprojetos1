<?php

namespace App\Filament\Widgets;

use App\Models\Task;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class UpcomingTasksWidget extends BaseWidget
{
    protected static ?string $heading = 'Próximas tarefas a vencer';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Task::query()->visibleTo(auth()->user())
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '>=', now()->toDateString())
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->orderBy('due_date')
            )
            ->columns([
                TextColumn::make('title')
                    ->label('Tarefa'),

                TextColumn::make('project.name')
                    ->label('Projeto'),

                TextColumn::make('responsible.name')
                    ->label('Responsável'),

                TextColumn::make('due_date')
                    ->label('Prazo')
                    ->date('d/m/Y'),
            ]);
    }
}
