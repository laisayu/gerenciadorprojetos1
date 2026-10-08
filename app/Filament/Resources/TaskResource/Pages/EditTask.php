<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use App\Services\TaskAccessService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditTask extends EditRecord
{
    protected static string $resource = TaskResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(TaskAccessService::class)->update(auth()->user(), $record, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $field): array => ["data.{$field}" => $messages])
                    ->all(),
            );
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
