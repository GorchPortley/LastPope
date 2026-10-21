<?php

use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Models\Order;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Livewire\Volt\Component;
use function Laravel\Folio\{middleware, name};

middleware('auth');
name('dashboard.orders');

new class extends Component implements HasForms, Tables\Contracts\HasTable {
    use InteractsWithForms, InteractsWithTable;

    public function table(Table $table): Table
    {
        $orderStatus = fn ($state): OrderStatus => $state instanceof OrderStatus ? $state : OrderStatus::from((string) $state);
        $partStatus = fn ($state): SellerOrderStatus => $state instanceof SellerOrderStatus ? $state : SellerOrderStatus::from((string) $state);

        return $table
            ->query(
                Order::query()
                    ->where('user_id', auth()->id())
                    ->where('status', '!=', OrderStatus::CANCELLED->value)
                    ->with(['sellerOrders.seller.sellerProfile', 'items'])
            )
            ->heading('Orders')
            ->description('Track what you bought, confirm parcels when they arrive, and open receipts.')
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->recordUrl(fn (Order $record): string => route('dashboard.orders.show', ['order' => $record->number]))
            ->columns([
                TextColumn::make('number')
                    ->label('Order')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Order $record): string => ($record->paid_at ?? $record->created_at)->format('M j, Y')),
                TextColumn::make('items_summary')
                    ->label('Items')
                    ->state(fn (Order $record): string => (string) $record->items->first()?->title)
                    ->description(fn (Order $record): ?string => $record->items->count() > 1 ? '+ '.($record->items->count() - 1).' more' : null)
                    ->limit(40)
                    ->visibleFrom('md')
                    ->searchable(query: fn ($query, string $search) => $query->whereHas('items', fn ($items) => $items->where('title', 'like', '%'.$search.'%'))),
                TextColumn::make('sold_by')
                    ->label('Sold by')
                    ->state(fn (Order $record): string => $record->sellerOrders
                        ->map(fn ($part) => $part->seller?->sellerProfile?->shop_name ?: ($part->seller?->name ?? 'Unknown seller'))
                        ->unique()->join(', '))
                    ->limit(36)
                    ->visibleFrom('lg'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $orderStatus($state)->label())
                    ->color(fn ($state): string => $orderStatus($state)->color()),
                TextColumn::make('delivery')
                    ->label('Delivery')
                    ->badge()
                    ->state(fn (Order $record): array => $record->sellerOrders->map(fn ($part) => $part->status->value)->unique()->values()->all())
                    ->formatStateUsing(fn ($state): string => $partStatus($state)->label())
                    ->color(fn ($state): string => $partStatus($state)->color())
                    ->visibleFrom('md'),
                TextColumn::make('total_cents')
                    ->label('Total')
                    ->formatStateUsing(fn ($state, Order $record): string => money((int) $state, $record->currency))
                    ->weight('medium')
                    ->alignEnd()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(OrderStatus::options())->except(OrderStatus::CANCELLED->value)->all())
                    ->multiple(),
            ])
            ->actions([
                Action::make('open')
                    ->label('Details')
                    ->icon('heroicon-m-eye')
                    ->url(fn (Order $record): string => route('dashboard.orders.show', ['order' => $record->number])),
            ])
            ->emptyStateIcon('phosphor-receipt')
            ->emptyStateHeading('No orders yet')
            ->emptyStateDescription('When you buy something, it shows up here.')
            ->emptyStateActions([
                Action::make('browse')->label('Browse the marketplace')->url(route('marketplace')),
            ]);
    }
}; ?>

<x-layouts.app>
    @volt('dashboard.orders')
    <div>
        {{ $this->table }}
    </div>
    @endvolt
</x-layouts.app>
