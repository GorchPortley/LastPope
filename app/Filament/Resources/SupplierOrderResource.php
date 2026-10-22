<?php

namespace App\Filament\Resources;

use App\Enums\SupplierOrderStatus;
use App\Filament\Resources\SupplierOrderResource\Pages;
use App\Filament\Resources\SupplierOrderResource\PurchaseOrderActions;
use App\Models\Shipment;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use App\Services\Marketplace\PurchaseOrderService;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The purchase orders (POs) to suppliers for the dropshipped lines of paid store orders (docs/PLAN.md 8.3). The queue
 * is where an admin works: send the new ones (by e-mail or by hand in the supplier's portal), confirm, enter what the
 * supplier charges, enter their tracking when they ship, cancel or flag trouble. The navigation badge counts the ones
 * waiting to be sent. Everything goes through PurchaseOrderService with the signed-in admin as the actor.
 */
class SupplierOrderResource extends Resource
{
    protected static ?string $model = SupplierOrder::class;

    protected static ?string $slug = 'purchase-orders';

    protected static ?string $navigationIcon = 'phosphor-clipboard-text-duotone';

    protected static ?string $navigationGroup = 'Store';

    protected static ?string $navigationLabel = 'Purchase orders';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'purchase order';

    protected static ?string $pluralModelLabel = 'purchase orders';

    protected static ?string $recordTitleAttribute = 'number';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /** Purchase orders waiting to be sent. */
    public static function getNavigationBadge(): ?string
    {
        $count = app(PurchaseOrderService::class)->toSendCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Purchase orders waiting to be sent to a supplier';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplier', 'order', 'items']))
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('PO')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->copyable()
                    ->description(fn (SupplierOrder $record): string => $record->created_at->format('M j, Y H:i')),
                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable()
                    ->description(fn (SupplierOrder $record): ?string => $record->supplier?->order_email ?: 'web portal'),
                Tables\Columns\TextColumn::make('order.number')
                    ->label('Order')
                    ->searchable()
                    ->url(fn (SupplierOrder $record): string => OrderResource::getUrl('view', ['record' => $record->order])),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => static::status($state)->label())
                    ->color(fn ($state): string => static::status($state)->color())
                    ->sortable(),
                Tables\Columns\TextColumn::make('lines')
                    ->label('Lines')
                    ->state(fn (SupplierOrder $record): string => $record->items->whereNull('cancelled_at')->sum('quantity').' '.\Illuminate\Support\Str::plural('unit', (int) $record->items->whereNull('cancelled_at')->sum('quantity')))
                    ->description(fn (SupplierOrder $record): ?string => $record->items->whereNull('cancelled_at')->first()?->supplier_sku),
                Tables\Columns\TextColumn::make('cost')
                    ->label('Cost to us')
                    ->state(fn (SupplierOrder $record): string => money($record->totalCostCents()))
                    ->description(fn (SupplierOrder $record): ?string => $record->shippingCostPending() ? 'shipping not entered' : null),
                Tables\Columns\TextColumn::make('supplier_reference')->label('Their reference')->placeholder('-')->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('sent_at')->label('Sent')->since()->sortable()->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('shipped_at')->label('Shipped')->since()->sortable()->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('supplier_id')->label('Supplier')->relationship('supplier', 'name')->searchable()->preload(),
                Tables\Filters\SelectFilter::make('status')->options(SupplierOrderStatus::options())->multiple(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Open')->iconButton()->tooltip('Open purchase order'),
                PurchaseOrderActions::send(Tables\Actions\Action::class)->iconButton()->tooltip('Send email'),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $money = fn ($state): string => money((int) $state);

        return $infolist
            ->schema([
                Infolists\Components\Section::make('Purchase order')
                    ->columns(4)
                    ->schema([
                        Infolists\Components\TextEntry::make('number')->label('PO number')->copyable()->weight('bold'),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => static::status($state)->label())
                            ->color(fn ($state): string => static::status($state)->color()),
                        Infolists\Components\TextEntry::make('supplier.name')
                            ->label('Supplier')
                            ->url(fn (SupplierOrder $record): ?string => $record->supplier ? SupplierResource::getUrl('edit', ['record' => $record->supplier]) : null)
                            ->helperText(fn (SupplierOrder $record): string => $record->supplier?->order_email ? 'Order e-mail: '.$record->supplier->order_email : 'Web portal: no order e-mail'),
                        Infolists\Components\TextEntry::make('order.number')
                            ->label('Store order')
                            ->url(fn (SupplierOrder $record): string => OrderResource::getUrl('view', ['record' => $record->order])),
                        Infolists\Components\TextEntry::make('supplier_reference')->label('Supplier\'s reference')->placeholder('-')->copyable(),
                        Infolists\Components\TextEntry::make('placed_manually')
                            ->label('Placed')
                            ->state(fn (SupplierOrder $record): string => match (true) {
                                $record->sent_at === null => 'not sent yet',
                                $record->placed_manually => 'by hand (portal or phone)',
                                default => 'by e-mail',
                            }),
                        Infolists\Components\TextEntry::make('supplier.blind_dropship')
                            ->label('Blind dropship')
                            ->formatStateUsing(fn ($state): string => $state ? 'Yes: no branding, invoice or prices in the parcel' : 'No')
                            ->columnSpan(2),
                    ]),

                Infolists\Components\Section::make('Ship to')
                    ->description('The buyer\'s address as it was at purchase time. The buyer\'s e-mail address never goes to a supplier.')
                    ->schema([
                        Infolists\Components\TextEntry::make('shipping_address')
                            ->hiddenLabel()
                            ->state(fn (SupplierOrder $record): string => implode('<br>', array_map('e', $record->shipToLines())))
                            ->html()
                            ->placeholder('No address'),
                    ]),

                Infolists\Components\Section::make('Lines')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->columns(6)
                            ->schema([
                                Infolists\Components\TextEntry::make('supplier_sku')->label('SKU')->placeholder('no SKU')->fontFamily('mono'),
                                Infolists\Components\TextEntry::make('title')
                                    ->label('Item')
                                    ->columnSpan(2)
                                    ->helperText(fn (SupplierOrderItem $record): ?string => $record->isCancelled() ? 'cancelled '.$record->cancelled_at->format('M j, Y') : null)
                                    ->color(fn (SupplierOrderItem $record): ?string => $record->isCancelled() ? 'gray' : null),
                                Infolists\Components\TextEntry::make('quantity')->label('Qty'),
                                Infolists\Components\TextEntry::make('unit_cost_cents')->label('Unit cost')->formatStateUsing($money),
                                Infolists\Components\TextEntry::make('line_cost')
                                    ->label('Line cost')
                                    ->state(fn (SupplierOrderItem $record): string => $record->isCancelled() ? '-' : money($record->lineCostCents())),
                            ]),
                    ]),

                Infolists\Components\Section::make('Costs and margin')
                    ->columns(5)
                    ->schema([
                        Infolists\Components\TextEntry::make('items_cost_cents')->label('Items')->formatStateUsing($money),
                        Infolists\Components\TextEntry::make('shipping_cost_cents')
                            ->label('Supplier shipping')
                            ->formatStateUsing($money)
                            ->placeholder('not entered yet')
                            ->color(fn (SupplierOrder $record): ?string => $record->shippingCostPending() ? 'warning' : null),
                        Infolists\Components\TextEntry::make('dropship_fee_cents')->label('Dropship fee')->formatStateUsing($money),
                        Infolists\Components\TextEntry::make('total_cost')
                            ->label('Total cost to us')
                            ->state(fn (SupplierOrder $record): string => money($record->totalCostCents()))
                            ->weight('bold'),
                        Infolists\Components\TextEntry::make('buyer_paid')
                            ->label('Buyer paid for these lines')
                            ->state(fn (SupplierOrder $record): string => money(static::buyerPaidCents($record)))
                            ->helperText(fn (SupplierOrder $record): string => 'Before Stripe fees: '.money(static::buyerPaidCents($record) - $record->totalCostCents()).' left after this purchase order.'),
                    ]),

                Infolists\Components\Section::make('History')
                    ->columns(5)
                    ->schema([
                        Infolists\Components\TextEntry::make('created_at')->label('Created (order paid)')->dateTime('M j, Y H:i'),
                        Infolists\Components\TextEntry::make('sent_at')->label('Sent')->dateTime('M j, Y H:i')->placeholder('-'),
                        Infolists\Components\TextEntry::make('confirmed_at')->label('Confirmed')->dateTime('M j, Y H:i')->placeholder('-'),
                        Infolists\Components\TextEntry::make('shipped_at')->label('Shipped')->dateTime('M j, Y H:i')->placeholder('-'),
                        Infolists\Components\TextEntry::make('cancelled_at')->label('Cancelled')->dateTime('M j, Y H:i')->placeholder('-'),
                        Infolists\Components\RepeatableEntry::make('shipments')
                            ->label('Parcels shipped by the supplier')
                            ->columnSpanFull()
                            ->columns(4)
                            ->visible(fn (SupplierOrder $record): bool => $record->shipments()->exists())
                            ->schema([
                                Infolists\Components\TextEntry::make('carrier'),
                                Infolists\Components\TextEntry::make('tracking_number')->label('Tracking')->copyable()->url(fn (Shipment $record): ?string => $record->tracking_url, shouldOpenInNewTab: true),
                                Infolists\Components\TextEntry::make('shipped_at')->label('Shipped')->dateTime('M j, Y H:i'),
                            ]),
                        Infolists\Components\TextEntry::make('notes')
                            ->label('Notes')
                            ->columnSpanFull()
                            ->placeholder('No notes')
                            ->formatStateUsing(fn ($state): string => nl2br(e((string) $state)))
                            ->html(),
                    ]),
            ]);
    }

    /** Items and shipping the buyer paid for the lines of this purchase order (cancelled lines excluded). */
    public static function buyerPaidCents(SupplierOrder $po): int
    {
        $po->loadMissing('items.orderItem');

        return (int) $po->items->whereNull('cancelled_at')->sum(fn (SupplierOrderItem $line) => (int) $line->orderItem?->line_total_cents + (int) $line->orderItem?->shipping_cents);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupplierOrders::route('/'),
            'view' => Pages\ViewSupplierOrder::route('/{record}'),
        ];
    }

    public static function status(mixed $state): SupplierOrderStatus
    {
        return $state instanceof SupplierOrderStatus ? $state : SupplierOrderStatus::from((string) $state);
    }
}
