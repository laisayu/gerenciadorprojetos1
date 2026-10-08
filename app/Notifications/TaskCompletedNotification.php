<?php

namespace App\Notifications;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class TaskCompletedNotification extends Notification
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
            ->title('Tarefa concluída')
            ->body('A tarefa "'.$this->taskTitle.'" foi concluída.')
            ->success()
            ->getDatabaseMessage();
    }
}
