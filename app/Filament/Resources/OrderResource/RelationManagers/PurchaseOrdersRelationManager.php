<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use App\Filament\Resources\SupplierOrderResource;
use App\Filament\Resources\SupplierOrderResource\PurchaseOrderActions;
use App\Models\SupplierOrder;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The purchase orders to suppliers of a store order (docs/PLAN.md 8.3): status at a glance, a link to the purchase order
 * page for everything else, and the one action that is needed most: send it.
 */
class PurchaseOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'supplierOrders';

    protected static ?string $title = 'Purchase orders';

    protected static ?string $icon = 'phosphor-clipboard-text-duotone';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->modifyQueryUsing(fn ($query) => $query->with(['supplier', 'items']))
            ->emptyStateHeading('No purchase orders')
            ->emptyStateDescription('Nothing in this order is shipped by a supplier.')
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('PO')->weight('medium')->copyable(),
                Tables\Columns\TextColumn::make('supplier.name')->label('Supplier'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => SupplierOrderResource::status($state)->label())
                    ->color(fn ($state): string => SupplierOrderResource::status($state)->color()),
                Tables\Columns\TextColumn::make('lines')
                    ->label('Lines')
                    ->state(fn (SupplierOrder $record): string => $record->items->whereNull('cancelled_at')->map(fn ($line) => ($line->quantity > 1 ? $line->quantity.' x ' : '').$line->title)->implode(', '))
                    ->limit(60),
                Tables\Columns\TextColumn::make('cost')
                    ->label('Cost to us')
                    ->state(fn (SupplierOrder $record): string => money($record->totalCostCents()))
                    ->description(fn (SupplierOrder $record): ?string => $record->shippingCostPending() ? 'shipping not entered' : null),
                Tables\Columns\TextColumn::make('supplier_reference')->label('Their reference')->placeholder('-'),
                Tables\Columns\TextColumn::make('sent_at')->label('Sent')->since()->placeholder('-'),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (SupplierOrder $record): string => SupplierOrderResource::getUrl('view', ['record' => $record])),
                PurchaseOrderActions::send(Tables\Actions\Action::class),
            ]);
    }
}
