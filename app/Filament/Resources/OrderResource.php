<?php

namespace App\Filament\Resources;

use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Resources\OrderResource\RelationManagers\SellerOrdersRelationManager;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Countries;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'phosphor-receipt-duotone';

    protected static ?string $navigationGroup = 'Marketplace';

    protected static ?int $navigationSort = 1;

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

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user'])->withCount('sellerOrders'))
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Order')->searchable()->sortable()->weight('medium'),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Buyer')
                    ->searchable(['name', 'email'])
                    ->description(fn (Order $record): ?string => $record->user?->email),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => static::status($state)->label())
                    ->color(fn ($state): string => static::status($state)->color()),
                Tables\Columns\TextColumn::make('total_cents')
                    ->label('Total')
                    ->formatStateUsing(fn ($state, Order $record): string => money((int) $state, $record->currency))
                    ->sortable(),
                Tables\Columns\TextColumn::make('platform_fee_cents')
                    ->label('Platform fee')
                    ->formatStateUsing(fn ($state, Order $record): string => money((int) $state, $record->currency))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('seller_orders_count')->label('Sellers')->alignCenter(),
                Tables\Columns\TextColumn::make('paid_at')->dateTime('M j, Y H:i')->sortable()->placeholder('not paid'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(OrderStatus::options())->multiple(),
                Tables\Filters\TernaryFilter::make('paid')
                    ->label('Payment')
                    ->placeholder('All orders')
                    ->trueLabel('Paid')
                    ->falseLabel('Not paid')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('paid_at'),
                        false: fn (Builder $query) => $query->whereNull('paid_at'),
                    ),
                Tables\Filters\Filter::make('reversal_pending')
                    ->label('Payout reversal to retry')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereHas('sellerOrders', fn (Builder $q) => static::reversalPending($q))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Order')
                    ->columns(4)
                    ->schema([
                        Infolists\Components\TextEntry::make('number')->label('Number')->copyable()->weight('bold'),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => static::status($state)->label())
                            ->color(fn ($state): string => static::status($state)->color()),
                        Infolists\Components\TextEntry::make('paid_at')->dateTime()->placeholder('not paid'),
                        Infolists\Components\TextEntry::make('created_at')->dateTime(),
                        Infolists\Components\TextEntry::make('stripe_checkout_session_id')->label('Checkout session')->copyable()->limit(30)->placeholder('-'),
                        Infolists\Components\TextEntry::make('stripe_payment_intent_id')->label('Payment intent')->copyable()->placeholder('-'),
                        Infolists\Components\TextEntry::make('stripe_charge_id')->label('Charge')->copyable()->placeholder('-'),
                        Infolists\Components\TextEntry::make('reserved_until')->label('Stock held until')->dateTime()->placeholder('-'),
                    ]),
                Infolists\Components\Section::make('Buyer and delivery')
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('user.name')
                            ->label('Buyer')
                            ->formatStateUsing(fn ($state, Order $record): string => $state.($record->user ? ' ('.$record->user->email.')' : ''))
                            ->url(fn (Order $record): ?string => $record->user ? UserResource::getUrl('edit', ['record' => $record->user]) : null),
                        Infolists\Components\TextEntry::make('shipping_address')
                            ->label('Shipping address')
                            ->state(fn (Order $record): string => static::addressLines($record->shipping_address))
                            ->html()
                            ->placeholder('Digital order: no address'),
                    ]),
                Infolists\Components\Section::make('Items')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('items')
                            ->label('')
                            ->columns(5)
                            ->schema([
                                Infolists\Components\TextEntry::make('title')->columnSpan(2),
                                Infolists\Components\TextEntry::make('quantity')->label('Qty'),
                                Infolists\Components\TextEntry::make('is_digital')
                                    ->label('Type')
                                    ->formatStateUsing(fn ($state): string => $state ? 'Digital' : 'Physical'),
                                Infolists\Components\TextEntry::make('line_total_cents')
                                    ->label('Line total')
                                    ->formatStateUsing(fn ($state, OrderItem $record): string => money((int) $state, $record->order?->currency)),
                            ]),
                    ]),
                Infolists\Components\Section::make('Totals')
                    ->columns(5)
                    ->schema([
                        Infolists\Components\TextEntry::make('subtotal_cents')->label('Items')->formatStateUsing(fn ($state, Order $record) => money((int) $state, $record->currency)),
                        Infolists\Components\TextEntry::make('shipping_cents')->label('Shipping')->formatStateUsing(fn ($state, Order $record) => money((int) $state, $record->currency)),
                        Infolists\Components\TextEntry::make('tax_cents')->label('Tax')->formatStateUsing(fn ($state, Order $record) => money((int) $state, $record->currency)),
                        Infolists\Components\TextEntry::make('total_cents')->label('Total')->weight('bold')->formatStateUsing(fn ($state, Order $record) => money((int) $state, $record->currency)),
                        Infolists\Components\TextEntry::make('platform_fee_cents')->label('Platform fee')->formatStateUsing(fn ($state, Order $record) => money((int) $state, $record->currency)),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            SellerOrdersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }

    /** Seller orders that were refunded after a payout, where taking the payout back still has to succeed. */
    public static function reversalPending(Builder $query): Builder
    {
        return $query
            ->where('status', SellerOrderStatus::REFUNDED->value)
            ->whereNotNull('stripe_transfer_id')
            ->whereNull('stripe_reversal_id')
            ->whereNotNull('transferred_at');
    }

    public static function status(mixed $state): OrderStatus
    {
        return $state instanceof OrderStatus ? $state : OrderStatus::from((string) $state);
    }

    /** @param  array<string, mixed>|null  $address */
    public static function addressLines(?array $address): string
    {
        if (blank($address)) {
            return '';
        }

        $lines = array_filter([
            $address['name'] ?? null,
            $address['line1'] ?? null,
            $address['line2'] ?? null,
            trim(($address['postal_code'] ?? '').' '.($address['city'] ?? '').(filled($address['state'] ?? null) ? ', '.$address['state'] : '')),
            Countries::name($address['country'] ?? null),
            filled($address['phone'] ?? null) ? 'Phone: '.$address['phone'] : null,
        ], fn ($line) => filled($line));

        return implode('<br>', array_map('e', $lines));
    }
}
