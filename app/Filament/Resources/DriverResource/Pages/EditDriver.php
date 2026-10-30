<?php

namespace App\Filament\Resources\DriverResource\Pages;

use App\Filament\Resources\Concerns\CatalogueActions;
use App\Filament\Resources\DriverResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDriver extends EditRecord
{
    protected static string $resource = DriverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CatalogueActions::viewOnSite(Actions\Action::class),
            CatalogueActions::snapshot(Actions\Action::class),
            DriverResource::collectDataAction(Actions\Action::class),
            CatalogueActions::transferOwnership(Actions\Action::class)
                ->after(fn () => $this->refreshFormData(['user_id', 'official'])),
            Actions\DeleteAction::make(),
        ];
    }
}
