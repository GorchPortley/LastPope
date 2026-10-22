<?php

namespace App\Filament\Resources\SupplierResource\Pages;

use App\Filament\Resources\SupplierResource;
use App\Models\Supplier;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('syncNow')
                ->label('Sync stock now')
                ->icon('heroicon-m-arrow-path')
                ->visible(fn (Supplier $record): bool => $record->hasFeed())
                ->action(function (Supplier $record): void {
                    SupplierResource::syncNow($record);

                    $this->record->refresh();
                    $this->refreshFormData(['feed_last_synced_at', 'feed_last_error']);
                }),
            Actions\Action::make('feedTemplate')
                ->label('Download feed template')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->action(fn (Supplier $record): StreamedResponse => SupplierResource::downloadTemplate($record)),
            Actions\DeleteAction::make()->visible(fn (Supplier $record): bool => SupplierResource::canBeDeleted($record)),
        ];
    }
}
