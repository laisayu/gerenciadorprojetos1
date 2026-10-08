<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Project extends Model
{
    public const COMPLETION_BLOCKED_MESSAGE = 'O projeto não pode ser concluído enquanto existirem tarefas pendentes.';

    protected $fillable = [
        'name',
        'description',
        'manager_id',
        'start_date',
        'end_date',
        'status',
        'priority',
    ];

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdministrator()) {
            return $query;
        }
        if (! $user->can('view_project')) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->hasRole('manager')) {
            return $query->where('manager_id', $user->id);
        }
        if ($user->hasRole('member')) {
            return $query->whereHas('members', fn (Builder $members): Builder => $members->whereKey($user->id));
        }

        return $query->whereRaw('1 = 0');
    }

    public function scopeManageableBy(Builder $query, User $user): Builder
    {
        if ($user->isAdministrator()) {
            return $query;
        }
        if ($user->hasRole('manager') && $user->can('update_project')) {
            return $query->where('manager_id', $user->id);
        }

        return $query->whereRaw('1 = 0');
    }

    protected static function booted(): void
    {
        static::saving(function (Project $project): void {
            if ($project->isDirty('end_date') && $project->end_date && $project->tasks()->whereDate('due_date', '>', $project->end_date)->exists()) {
                throw ValidationException::withMessages([
                    'end_date' => 'O término do projeto não pode ser anterior à data de entrega das tarefas existentes.',
                ]);
            }

            if ($project->status === 'completed' && $project->hasPendingTasks()) {
                throw ValidationException::withMessages([
                    'status' => self::COMPLETION_BLOCKED_MESSAGE,
                ]);
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function hasPendingTasks(): bool
    {
        return $this->tasks()
            ->where(function (Builder $query): void {
                $query->whereNotIn('status', Task::FINISHED_STATUSES)
                    ->orWhereNull('status');
            })
            ->exists();
    }

    public function complete(): void
    {
        $this->getConnection()->transaction(function (): void {
            $project = $this->newQuery()->lockForUpdate()->findOrFail($this->getKey());
            $project->update(['status' => 'completed']);
        });

        $this->refresh();
    }
    public function getProgressPercentageAttribute(): int
{
    $totalTasks = $this->tasks()->count();

    if ($totalTasks === 0) {
        return 0;
    }

    $completedTasks = $this->tasks()
        ->where('status', 'completed')
        ->count();

    return (int) round(($completedTasks / $totalTasks) * 100);
}

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
