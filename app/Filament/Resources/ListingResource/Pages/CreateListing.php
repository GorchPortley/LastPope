<?php

namespace App\Filament\Resources\ListingResource\Pages;

use App\Filament\Resources\ListingResource;
use App\Models\Listing;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateListing extends CreateRecord
{
    protected static string $resource = ListingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $uuid = (string) ($data['uuid'] ?? '');
        $data['uuid'] = Str::isUuid($uuid) && ! Listing::withTrashed()->where('uuid', $uuid)->exists() ? $uuid : (string) Str::uuid();

        return $data;
    }

    protected function afterCreate(): void
    {
        ListingResource::warnAboutBlockers($this->record, $this->record->status->value);
    }
}
