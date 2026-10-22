<?php

use App\Enums\IssueStatus;
use App\Models\OrderIssue;
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
name('dashboard.issues');

new class extends Component implements HasForms, Tables\Contracts\HasTable {
    use InteractsWithForms, InteractsWithTable;

    public function table(Table $table): Table
    {
        $status = fn ($state): IssueStatus => $state instanceof IssueStatus ? $state : IssueStatus::from((string) $state);
        $user = auth()->user();

        return $table
            ->query(
                OrderIssue::query()
                    ->involving($user)
                    ->with(['order', 'sellerOrder.items', 'buyer', 'seller.sellerProfile'])
            )
            ->heading('Problem reports')
            ->description('Problems you reported as a buyer, and problems buyers reported about your sales.')
            ->defaultSort('last_message_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->recordUrl(fn (OrderIssue $record): string => $record->url())
            ->columns([
                TextColumn::make('order.number')
                    ->label('Order')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (OrderIssue $record): string => $record->created_at->format('M j, Y')),
                TextColumn::make('items_summary')
                    ->label('Items')
                    ->state(fn (OrderIssue $record): string => (string) $record->sellerOrder->items->first()?->title)
                    ->description(fn (OrderIssue $record): ?string => $record->sellerOrder->items->count() > 1 ? '+ '.($record->sellerOrder->items->count() - 1).' more' : null)
                    ->limit(40)
                    ->visibleFrom('md'),
                TextColumn::make('role')
                    ->label('You are the')
                    ->state(fn (OrderIssue $record): string => $record->isBuyer(auth()->user()) ? 'Buyer' : 'Seller')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Buyer' ? 'info' : 'primary')
                    ->visibleFrom('lg'),
                TextColumn::make('reason')
                    ->state(fn (OrderIssue $record): string => $record->reason->label())
                    ->limit(36),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $status($state)->label())
                    ->color(fn ($state): string => $status($state)->color()),
                TextColumn::make('last_message_at')
                    ->label('Last activity')
                    ->since()
                    ->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('status')->options(IssueStatus::options())->multiple(),
            ])
            ->actions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-m-eye')
                    ->url(fn (OrderIssue $record): string => $record->url()),
            ])
            ->emptyStateIcon('phosphor-chats-circle')
            ->emptyStateHeading('No problem reports')
            ->emptyStateDescription('If something is wrong with an order, open the order and choose "Report a problem".')
            ->emptyStateActions([
                Action::make('orders')->label('My orders')->url(route('dashboard.orders')),
            ]);
    }
}; ?>

<x-layouts.app>
    @volt('dashboard.issues')
    <div>
        {{ $this->table }}
    </div>
    @endvolt
</x-layouts.app>
