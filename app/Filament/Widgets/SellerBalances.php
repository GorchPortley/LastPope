<?php

namespace App\Filament\Widgets;

use App\Models\SellerProfile;
use App\Models\StripeBalance;
use App\Models\StripePayout;
use App\Services\Marketplace\Ledger\SellerEarnings;
use App\Support\StripeMode;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin > Finance > Stripe ledger, below the transactions: every seller with a Stripe account, their balance in Stripe
 * (available, pending, on its way to the bank), their next and last payout, and the money the site still holds for them
 * (payouts not made yet: waiting for shipment, for the payout delay, or on hold). Not placed on the dashboard by itself.
 */
class SellerBalances extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?string $heading = 'Seller balances';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasRole('admin');
    }

    public function table(Table $table): Table
    {
        $live = StripeMode::isLive();
        $earnings = app(SellerEarnings::class);
        $money = fn (?StripeBalance $balance, string $column): string => $balance === null ? '-' : money((int) $balance->{$column}, $balance->currency);
        $main = fn (SellerProfile $record): ?StripeBalance => $record->stripeBalances->first();

        return $table
            ->query(
                SellerProfile::query()
                    ->whereNotNull('stripe_account_id')
                    ->where('stripe_account_id', 'not like', 'acct_demo%')
                    ->with([
                        'user',
                        'stripeBalances' => fn ($query) => $query->where('livemode', $live)->orderByRaw('currency = ? desc', [strtolower((string) config('marketplace.currency', 'usd'))]),
                        'latestStripePayout',
                        'nextStripePayout',
                    ])
            )
            ->description('What each seller has in their own Stripe account, and what the site still holds for them.')
            ->defaultSort('shop_name')
            ->defaultPaginationPageOption(10)
            ->columns([
                Tables\Columns\TextColumn::make('shop_name')
                    ->label('Seller')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (SellerProfile $record): ?string => $record->stripe_account_id),
                Tables\Columns\TextColumn::make('available')
                    ->label('Available')
                    ->state(fn (SellerProfile $record): string => $money($main($record), 'available_cents'))
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('pending')
                    ->label('Pending')
                    ->state(fn (SellerProfile $record): string => $money($main($record), 'pending_cents'))
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('in_transit')
                    ->label('On its way to the bank')
                    ->state(fn (SellerProfile $record): string => $money($main($record), 'in_transit_cents'))
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('next_payout')
                    ->label('Next payout')
                    ->state(fn (SellerProfile $record): ?string => $record->nextStripePayout ? money($record->nextStripePayout->amount_cents, $record->nextStripePayout->currency) : null)
                    ->description(fn (SellerProfile $record): ?string => $record->nextStripePayout?->arrival_date?->format('M j'))
                    ->placeholder('-')
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('last_payout')
                    ->label('Last payout')
                    ->state(fn (SellerProfile $record): ?string => $record->latestStripePayout ? money($record->latestStripePayout->amount_cents, $record->latestStripePayout->currency) : null)
                    ->description(fn (SellerProfile $record): ?string => $record->latestStripePayout ? $record->latestStripePayout->statusLabel().' '.$record->latestStripePayout->stripe_created_at->format('M j') : null)
                    ->color(fn (SellerProfile $record): ?string => $record->latestStripePayout?->isFailed() ? 'danger' : null)
                    ->placeholder('-')
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('held')
                    ->label('Held by the site')
                    ->state(fn (SellerProfile $record): string => money($earnings->holdingCents($record->user)))
                    ->description('payouts not made yet')
                    ->alignEnd(),
            ])
            ->actions([
                Tables\Actions\Action::make('stripe')
                    ->label('Open in Stripe')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->iconButton()
                    ->tooltip('Open in Stripe')
                    ->color('gray')
                    ->url(fn (SellerProfile $record): string => StripeMode::dashboardUrl('connect/accounts/'.$record->stripe_account_id))
                    ->openUrlInNewTab(),
            ])
            ->emptyStateIcon('phosphor-storefront')
            ->emptyStateHeading('No sellers with a Stripe account yet');
    }
}
