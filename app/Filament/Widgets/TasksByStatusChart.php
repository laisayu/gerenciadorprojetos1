<?php

namespace App\Filament\Widgets;

use App\Models\Task;
use Filament\Widgets\ChartWidget;

class TasksByStatusChart extends ChartWidget
{
    protected static ?string $heading = 'Tarefas por situação';

    protected function getData(): array
    {
        return [
            'datasets' => [
                [
                    'label' => 'Quantidade de tarefas',
                    'data' => [
                        Task::query()->visibleTo(auth()->user())->where('status', 'backlog')->count(),
                        Task::query()->visibleTo(auth()->user())->where('status', 'todo')->count(),
                        Task::query()->visibleTo(auth()->user())->where('status', 'in_progress')->count(),
                        Task::query()->visibleTo(auth()->user())->where('status', 'review')->count(),
                        Task::query()->visibleTo(auth()->user())->where('status', 'completed')->count(),
                        Task::query()->visibleTo(auth()->user())->where('status', 'cancelled')->count(),
                    ],
                ],
            ],
            'labels' => [
                'Não iniciada',
                'A fazer',
                'Em andamento',
                'Em revisão',
                'Concluídas',
                'Canceladas',
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
