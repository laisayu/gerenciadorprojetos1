<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Gate::authorize('update', $record);
        if (! auth()->user()->isAdministrator()) {
            $data['manager_id'] = $record->manager_id;
        }

        return $record->getConnection()->transaction(function () use ($record, $data): Model {
            $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());

            try {
                return parent::handleRecordUpdate($record, $data);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(
                    collect($exception->errors())
                        ->mapWithKeys(fn (array $messages, string $field): array => ["data.{$field}" => $messages])
                        ->all(),
                );
            }
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
