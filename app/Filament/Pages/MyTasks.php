<?php

namespace App\Filament\Pages;

use App\Models\Task;
use Filament\Pages\Page;

class MyTasks extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-check-circle';

    protected static string $view = 'filament.pages.my-tasks';

    protected static ?string $navigationLabel = 'Minhas Tarefas';

    protected static ?string $title = 'Minhas Tarefas';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', Task::class) ?? false;
    }

    public string $filter = 'all';

    public function getTasks()
    {
        $query = Task::query()->visibleTo(auth()->user())
            ->where('responsible_id', auth()->id());

        return match ($this->filter) {
            'todo' => $query->where('status', 'todo')->get(),

            'in_progress' => $query->where('status', 'in_progress')->get(),

            'review' => $query->where('status', 'review')->get(),

            'completed' => $query->where('status', 'completed')->get(),

            'overdue' => $query
                ->whereDate('due_date', '<', now())
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->get(),

            default => $query->get(),
        };
    }
}
