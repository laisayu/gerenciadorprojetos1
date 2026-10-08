<?php

namespace App\Models;

use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskCompletedNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Task extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const STATUSES = [
        'backlog',
        'todo',
        'in_progress',
        'review',
        'completed',
        'cancelled',
    ];

    public const FINISHED_STATUSES = [
        'completed',
        'cancelled',
    ];

    public const COMPLETION_BLOCKED_MESSAGE =
        'Conclua todos os itens da lista de verificação antes de concluir a tarefa.';

    protected $fillable = [
        'project_id',
        'responsible_id',
        'created_by',
        'title',
        'description',
        'priority',
        'status',
        'start_date',
        'due_date',
        'completed_at',
        'estimated_hours',
    ];

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdministrator()) {
            return $query;
        }

        if (! $user->can('view_task')) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('manager')) {
            return $query->whereHas(
                'project',
                fn (Builder $projects): Builder => $projects->where('manager_id', $user->id)
            );
        }

        if ($user->hasRole('member')) {
            return $query
                ->where('responsible_id', $user->id)
                ->whereHas(
                    'project.members',
                    fn (Builder $members): Builder => $members->whereKey($user->id)
                );
        }

        return $query->whereRaw('1 = 0');
    }

    public function scopeManageableBy(Builder $query, User $user): Builder
    {
        if ($user->isAdministrator()) {
            return $query;
        }

        if ($user->hasRole('manager') && $user->can('update_task')) {
            return $query->whereHas(
                'project',
                fn (Builder $projects): Builder => $projects->where('manager_id', $user->id)
            );
        }

        return $query->whereRaw('1 = 0');
    }

    protected static function booted(): void
    {
        /*
         * Validações executadas sempre que uma tarefa é salva.
         */
        static::saving(function (Task $task): void {
            if (
                $task->status === 'completed' &&
                $task->hasPendingSubtasks()
            ) {
                throw ValidationException::withMessages([
                    'status' => self::COMPLETION_BLOCKED_MESSAGE,
                ]);
            }

            $project = Project::find($task->project_id);

            if (! $project) {
                return;
            }

            /*
             * Projeto concluído não pode receber/reabrir
             * tarefas pendentes.
             */
            if (
                $project->status === 'completed' &&
                ! in_array(
                    $task->status,
                    self::FINISHED_STATUSES,
                    true
                )
            ) {
                throw ValidationException::withMessages([
                    'status' => 'Reabra o projeto antes de adicionar ou reabrir tarefas pendentes.',
                ]);
            }

            /*
             * Prazo da tarefa não pode ser anterior
             * ao início do projeto.
             */
            if (
                $task->due_date &&
                $project->start_date &&
                $task->due_date < $project->start_date
            ) {
                throw ValidationException::withMessages([
                    'due_date' => 'A data limite não pode ser anterior ao início do projeto.',
                ]);
            }

            /*
             * Prazo da tarefa não pode ultrapassar
             * o término do projeto.
             */
            if (
                $task->due_date &&
                $project->end_date &&
                $task->due_date > $project->end_date
            ) {
                throw ValidationException::withMessages([
                    'due_date' => 'A data limite não pode ser posterior ao término do projeto.',
                ]);
            }
        });

        static::created(function (Task $task): void {
            $task->responsible()->first()?->notify(
                new TaskAssignedNotification($task->title)
            );
        });

        static::updated(function (Task $task): void {
            if ($task->wasChanged('status') && $task->status === 'completed') {
                $task->responsible()->first()?->notify(
                    new TaskCompletedNotification($task->title)
                );
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function hasPendingSubtasks(): bool
    {
        return $this->subtasks()
            ->where(function (Builder $query): void {
                $query
                    ->where('is_completed', false)
                    ->orWhereNull('is_completed');
            })
            ->exists();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'responsible_id'
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Subtask::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }
}
