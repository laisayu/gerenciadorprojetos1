<x-filament-panels::page>

    <div class="flex flex-wrap gap-2 mb-6">

        <x-filament::button
            wire:click="$set('filter', 'all')"
        >
            Todas
        </x-filament::button>

        <x-filament::button
            wire:click="$set('filter', 'todo')"
        >
            A fazer
        </x-filament::button>

        <x-filament::button
            wire:click="$set('filter', 'in_progress')"
        >
            Em andamento
        </x-filament::button>

        <x-filament::button
            wire:click="$set('filter', 'review')"
        >
            Em revisão
        </x-filament::button>

        <x-filament::button
            wire:click="$set('filter', 'overdue')"
        >
            Atrasadas
        </x-filament::button>

        <x-filament::button
            wire:click="$set('filter', 'completed')"
        >
            Concluídas
        </x-filament::button>

    </div>


    <div class="space-y-4">

        @forelse ($this->getTasks() as $task)

            <div class="p-4 bg-white rounded-xl shadow">

                <h2 class="text-lg font-bold">
                    <a href="{{ \App\Filament\Resources\TaskResource::getUrl('view', ['record' => $task]) }}">{{ $task->title }}</a>
                </h2>

                <p>
                    Projeto:
                    {{ $task->project?->name }}
                </p>

                <p>
                    Situação:
                    {{ \App\Filament\Resources\TaskResource::STATUS_LABELS[$task->status] ?? $task->status }}
                </p>

                <p>
                    Prioridade:
                    {{ \App\Filament\Resources\TaskResource::PRIORITY_LABELS[$task->priority] ?? $task->priority }}
                </p>

                <p>
                    Prazo:
                    {{ $task->due_date ?? 'Sem prazo' }}
                </p>

            </div>

        @empty

            <p>
                Nenhuma tarefa encontrada.
            </p>

        @endforelse

    </div>

</x-filament-panels::page>
