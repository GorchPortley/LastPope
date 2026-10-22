<?php

namespace App\Filament\Resources\ListingResource\Pages;

use App\Filament\Resources\ListingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditListing extends EditRecord
{
    protected static string $resource = ListingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ListingResource::normalizeFulfilment($data);
    }

    protected function beforeSave(): void
    {
        if (! ListingResource::passesChannelRules($this->form->getState(), $this->record)) {
            $this->halt();
        }
    }

    protected function afterSave(): void
    {
        ListingResource::warnAboutBlockers($this->record, $this->record->status->value);
    }
}
