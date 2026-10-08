<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use App\Models\Task;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProjectStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $activeProjects = Project::query()->visibleTo(auth()->user())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();

        $myTasks = Task::query()->visibleTo(auth()->user())
            ->where('responsible_id', auth()->id())
            ->count();

        $overdueTasks = Task::query()->visibleTo(auth()->user())
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();

        $completedThisMonth = Task::query()->visibleTo(auth()->user())
            ->where('status', 'completed')
            ->whereMonth('completed_at', now()->month)
            ->whereYear('completed_at', now()->year)
            ->count();

        return [
            Stat::make('Projetos ativos', $activeProjects),

            Stat::make('Minhas tarefas', $myTasks),

            Stat::make('Tarefas atrasadas', $overdueTasks),

            Stat::make('Concluídas no mês', $completedThisMonth),
        ];
    }
}
