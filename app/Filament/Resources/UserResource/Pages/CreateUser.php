<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        Gate::authorize('create', User::class);

        return DB::transaction(function () use ($data): User {
            $user = User::create(Arr::only($data, ['name', 'email', 'password']));
            $user->syncRoles($data['role']);

            return $user;
        });
    }
}
