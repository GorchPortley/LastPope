<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\SellerProfileResource;
use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('shop')
                ->label('Seller profile')
                ->icon('phosphor-storefront')
                ->color('gray')
                ->visible(fn (): bool => $this->record->sellerProfile !== null)
                ->url(fn (): string => SellerProfileResource::getUrl('edit', ['record' => $this->record->sellerProfile])),
            Actions\Action::make('impersonate')
                ->icon('heroicon-m-user')
                ->color('gray')
                ->visible(fn (): bool => auth()->id() !== $this->record->getKey())
                ->url(fn (): string => route('impersonate', $this->record)),
            UserResource::verifyEmailAction(Actions\Action::class),
            UserResource::resetTwoFactorAction(Actions\Action::class),
            Actions\DeleteAction::make(),
        ];
    }

    /** Several user columns are not mass assignable (e-mail verification, chat settings); admins may set them all. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->forceFill(UserResource::withDefaultAvatar($data))->save();

        return $record;
    }
}
