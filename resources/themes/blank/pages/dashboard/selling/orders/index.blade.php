<?php

use App\Enums\SellerOrderStatus;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Models\SellerOrder;
use App\Services\Marketplace\ShipmentService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Volt\Component;
use function Laravel\Folio\{middleware, name};

middleware('auth');
name('selling.orders');

new class extends Component implements HasForms, Tables\Contracts\HasTable {
    use InteractsWithForms, InteractsWithTable;

    public function table(Table $table): Table
    {
        $status = fn ($state): SellerOrderStatus => $state instanceof SellerOrderStatus ? $state : SellerOrderStatus::from((string) $state);

        return $table
            ->query(
                SellerOrder::query()
                    ->where('seller_id', auth()->id())
                    ->where('status', '!=', SellerOrderStatus::PENDING->value)
                    ->with(['order', 'items'])
            )
            ->heading('Sales')
            ->description('Orders that contain your items. Add the tracking number when you ship physical items; your payout is released then.')
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('order.number')
                    ->label('Order')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (SellerOrder $record): string => ($record->order->paid_at ?? $record->created_at)->format('M j, Y')),
                TextColumn::make('items_summary')
                    ->label('Items')
                    ->state(fn (SellerOrder $record): string => (string) $record->items->first()?->title)
                    ->description(fn (SellerOrder $record): ?string => $record->items->count() > 1 ? '+ '.($record->items->count() - 1).' more' : null)
                    ->limit(40),
                TextColumn::make('ship_to')
                    ->label('Ship to')
                    ->state(function (SellerOrder $record): string {
                        $address = $record->order->shipping_address;

                        return $record->hasPhysicalItems() && filled($address)
                            ? trim(($address['name'] ?? '').', '.($address['city'] ?? '').' '.($address['country'] ?? ''), ', ')
                            : 'Digital delivery';
                    })
                    ->visibleFrom('md')
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $status($state)->label())
                    ->color(fn ($state): string => $status($state)->color()),
                TextColumn::make('total')
                    ->label('Total')
                    ->state(fn (SellerOrder $record): string => money($record->grossCents(), $record->order->currency))
                    ->alignEnd(),
                TextColumn::make('payout_cents')
                    ->label('Your payout')
                    ->state(fn (SellerOrder $record): string => money($record->payout_cents, $record->order->currency))
                    ->description(fn (SellerOrder $record): string => $record->isTransferred() ? 'paid out' : 'pending')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(SellerOrderStatus::options())->except(SellerOrderStatus::PENDING->value)->all())
                    ->multiple(),
                Filter::make('to_ship')
                    ->label('Needs shipping')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('status', SellerOrderStatus::AWAITING_SHIPMENT->value)),
                SelectFilter::make('type')
                    ->label('Type')
                    ->options(['physical' => 'Physical items', 'digital' => 'Digital only'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'physical' => $query->whereHas('items', fn ($q) => $q->where('is_digital', false)),
                        'digital' => $query->whereDoesntHave('items', fn ($q) => $q->where('is_digital', false)),
                        default => $query,
                    }),
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->actions([
                Action::make('details')
                    ->label('Details')
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->modalHeading(fn (SellerOrder $record): string => 'Order '.$record->order->number)
                    ->modalContent(fn (SellerOrder $record) => view('marketplace.seller-order-details', ['sellerOrder' => $record->loadMissing(['order', 'items'])]))
                    ->modalWidth('lg')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('ship')
                    ->label(fn (SellerOrder $record): string => $record->status === SellerOrderStatus::SHIPPED ? 'Update tracking' : 'Mark as shipped')
                    ->icon('heroicon-m-truck')
                    ->visible(fn (SellerOrder $record): bool => in_array($record->status, [SellerOrderStatus::AWAITING_SHIPMENT, SellerOrderStatus::SHIPPED], true)
                        && (int) $record->seller_id === (int) auth()->id())
                    ->modalHeading(fn (SellerOrder $record): string => 'Ship order '.$record->order->number)
                    ->modalWidth('lg')
                    ->modalDescription('The buyer gets an e-mail with these details. Your payout is released when you mark the order as shipped.')
                    ->modalSubmitActionLabel('Save and notify buyer')
                    ->fillForm(fn (SellerOrder $record): array => [
                        'carrier' => $record->carrier,
                        'tracking_number' => $record->tracking_number,
                        'tracking_url' => $record->tracking_url,
                    ])
                    ->form([
                        TextInput::make('carrier')
                            ->label('Carrier')
                            ->required()
                            ->maxLength(100)
                            ->datalist(['USPS', 'UPS', 'FedEx', 'DHL', 'Royal Mail', 'Canada Post', 'Australia Post', 'Deutsche Post', 'PostNL', 'La Poste']),
                        TextInput::make('tracking_number')
                            ->label('Tracking number')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('tracking_url')
                            ->label('Tracking link (optional)')
                            ->url()
                            ->maxLength(500)
                            ->placeholder('https://'),
                    ])
                    ->action(function (array $data, SellerOrder $record): void {
                        try {
                            app(ShipmentService::class)->markShipped(auth()->user(), $record, $data['carrier'], $data['tracking_number'], $data['tracking_url'] ?? null);
                        } catch (InvalidOrderStateException $e) {
                            Notification::make()->danger()->title('Could not ship this order')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Marked as shipped')->body('The buyer has been notified.')->send();
                    }),
            ])
            ->emptyStateHeading('No sales yet')
            ->emptyStateDescription('Orders show up here as soon as a buyer pays for one of your items.');
    }
}; ?>

<x-layouts.app>
    @volt('selling.orders')
    <div>
        {{ $this->table }}
    </div>
    @endvolt
</x-layouts.app>
