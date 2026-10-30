<?php

namespace App\Filament\Resources\DesignResource\Pages;

use App\Filament\Resources\DesignResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDesign extends CreateRecord
{
    protected static string $resource = DesignResource::class;

    protected function afterCreate(): void
    {
        DesignResource::warnAboutBlockers($this->record, (bool) $this->record->active);
    }
}
