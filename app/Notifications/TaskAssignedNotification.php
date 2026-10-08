<?php

namespace App\Notifications;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class TaskAssignedNotification extends Notification
{
    public function __construct(
        public string $taskTitle
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Nova tarefa atribuída')
            ->body(
                'A tarefa "'.$this->taskTitle.'" foi atribuída a você.'
            )
            ->info()
            ->getDatabaseMessage();
    }
}
