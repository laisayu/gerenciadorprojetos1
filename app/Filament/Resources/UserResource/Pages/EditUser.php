<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['role'] = $this->record->roles()->value('name');
        $data['password'] = null;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Gate::authorize('update', $record);

        return DB::transaction(function () use ($record, $data) {
            $admins = User::role('admin')->lockForUpdate()->get();
            if ($record->isAdministrator() && $data['role'] !== 'admin' && $admins->count() <= 1) {
                throw ValidationException::withMessages(['data.role' => 'Mantenha pelo menos um administrador.']);
            }
            $record->update(Arr::only($data, ['name', 'email', 'password']));
            $record->syncRoles($data['role']);

            return $record;
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
