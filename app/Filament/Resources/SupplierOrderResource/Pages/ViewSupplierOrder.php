<?php

namespace App\Filament\Resources\SupplierOrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\SupplierOrderResource;
use App\Filament\Resources\SupplierOrderResource\PurchaseOrderActions;
use App\Models\SupplierOrder;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * One purchase order and what to do with it. Every action calls PurchaseOrderService with the signed-in admin; the page
 * reloads its record afterwards so the status, costs and history show the new state.
 */
class ViewSupplierOrder extends ViewRecord
{
    protected static string $resource = SupplierOrderResource::class;

    public function getTitle(): string
    {
        return 'Purchase order '.$this->record->number;
    }

    public function getSubheading(): ?string
    {
        return $this->record->supplier?->name.' - store order '.$this->record->order?->number;
    }

    protected function getHeaderActions(): array
    {
        $actions = Actions\Action::class;

        return [
            PurchaseOrderActions::send($actions),
            PurchaseOrderActions::placedManually($actions),
            PurchaseOrderActions::confirm($actions),
            PurchaseOrderActions::markShipped($actions),
            Actions\ActionGroup::make([
                PurchaseOrderActions::recordCosts($actions),
                PurchaseOrderActions::downloadCsv($actions),
                PurchaseOrderActions::flagProblem($actions),
                PurchaseOrderActions::cancel($actions),
                Actions\Action::make('openOrder')
                    ->label('Open store order')
                    ->icon('heroicon-m-receipt-percent')
                    ->color('gray')
                    ->url(fn (SupplierOrder $record): string => OrderResource::getUrl('view', ['record' => $record->order])),
            ])
                ->label('More')
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->button(),
        ];
    }

    /** Show what the last action changed: the record, and the cached infolist state with it. */
    protected function afterActionCalled(): void
    {
        $this->record->refresh()->load(['supplier', 'order', 'items.orderItem', 'shipments']);
    }
}
