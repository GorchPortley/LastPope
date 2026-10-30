<?php

namespace App\Filament\Resources\DesignResource\Pages;

use App\Filament\Resources\Concerns\CatalogueActions;
use App\Filament\Resources\DesignResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDesign extends EditRecord
{
    protected static string $resource = DesignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CatalogueActions::viewOnSite(Actions\Action::class),
            CatalogueActions::snapshot(Actions\Action::class),
            CatalogueActions::transferOwnership(Actions\Action::class)
                ->after(fn () => $this->refreshFormData(['user_id', 'official'])),
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        if ($this->record->wasChanged('active') || $this->record->wasChanged('price_cents')) {
            DesignResource::warnAboutBlockers($this->record, (bool) $this->record->active);
        }
    }
}
