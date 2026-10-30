<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** Several user columns are not mass assignable (e-mail verification, chat settings); admins may set them all. */
    protected function handleRecordCreation(array $data): Model
    {
        $user = new User;
        $user->forceFill(UserResource::withDefaultAvatar($data))->save();

        return $user;
    }
}
