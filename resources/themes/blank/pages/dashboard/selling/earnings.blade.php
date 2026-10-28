<?php

use App\Models\StripeBalanceTransaction;
use App\Services\Marketplace\Ledger\LedgerSync;
use App\Services\Marketplace\Ledger\SellerEarnings;
use App\Services\Marketplace\PayoutService;
use App\Support\StripeMode;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Table;
use Livewire\Volt\Component;
use function Laravel\Folio\{middleware, name};

middleware('auth');
name('selling.earnings');

/*
| The seller's money as Stripe sees it (docs/PLAN.md 11, R6.2): balance, payouts to their bank, transfers from the site, and what the
| site still holds. Everything is read from the seller's own Stripe account (App\Services\Marketplace\Ledger\SellerEarnings).
*/
new class extends Component implements HasForms, Tables\Contracts\HasTable {
    use InteractsWithForms, InteractsWithTable;

    public function mount(): void
    {
        // A balance older than a few minutes is asked from Stripe again, and a full sync is queued.
        app(SellerEarnings::class)->refreshIfStale(auth()->user());
    }

    /** The "Refresh" button: ask Stripe for the balance now. */
    public function refreshBalance(): void
    {
        $account = app(SellerEarnings::class)->account(auth()->user());

        if ($account !== null && app(LedgerSync::class)->refreshBalance($account)) {
            \App\Jobs\SyncStripeLedger::queueFor($account);
            Notification::make()->success()->title('Balance updated')->send();

            return;
        }

        Notification::make()->danger()->title('Could not reach Stripe')->body('Try again in a moment.')->send();
    }

    /** What SDLabs transferred to this seller's Stripe account, and what it took back, newest first. */
    public function table(Table $table): Table
    {
        return $table
            ->query(app(SellerEarnings::class)->transfers(auth()->user()))
            ->heading('Transfers from '.config('app.name'))
            ->description('Each sale is transferred to your Stripe account when it is paid out. Refunds take that money back.')
            ->defaultSort('stripe_created_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('order.number')
                    ->label('Order')
                    ->weight('medium')
                    ->placeholder('-')
                    ->description(fn (StripeBalanceTransaction $record): string => $record->stripe_created_at->format('M j, Y')),
                TextColumn::make('what')
                    ->label('What')
                    ->state(fn (StripeBalanceTransaction $record): string => $record->type === 'payment' ? 'Payout for your sale' : 'Taken back for a refund')
                    ->description(fn (StripeBalanceTransaction $record): ?string => $record->sellerOrder?->items?->first()?->title),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state, StripeBalanceTransaction $record): string => money((int) $state, $record->currency))
                    ->color(fn (StripeBalanceTransaction $record): ?string => $record->amount_cents < 0 ? 'danger' : null)
                    ->weight('medium')
                    ->alignEnd(),
                TextColumn::make('available_on')
                    ->label('In your balance')
                    ->state(fn (StripeBalanceTransaction $record): string => $record->isPending() ? 'pending until '.$record->available_on->format('M j') : 'available')
                    ->badge()
                    ->color(fn (StripeBalanceTransaction $record): string => $record->isPending() ? 'warning' : 'success'),
            ])
            ->actions([
                Action::make('sales')->label('Sales')->color('gray')->icon('heroicon-m-eye')->url(route('selling.orders')),
            ])
            ->emptyStateIcon('phosphor-hand-coins')
            ->emptyStateHeading('No transfers yet')
            ->emptyStateDescription('When a sale is paid out, the transfer shows up here.');
    }

    public function with(): array
    {
        $user = auth()->user();
        $earnings = app(SellerEarnings::class);
        $profile = $user->sellerProfile;
        $payouts = app(PayoutService::class);

        $state = match (true) {
            $user->isPlatformSeller() => 'platform',
            $profile === null || blank($profile->stripe_account_id) => 'none',
            StripeMode::isDemoAccount($profile->stripe_account_id) => 'demo',
            default => 'ready',
        };

        $balances = $earnings->balances($user);

        return [
            'state' => $state,
            'balances' => $balances,
            'refreshedAt' => $balances->max('refreshed_at'),
            'payouts' => $earnings->payouts($user),
            'upcoming' => $earnings->upcoming($user),
            'holding' => $earnings->holdingCents($user),
            'paidOut' => $earnings->paidOut($user),
            'payoutNote' => $payouts->timingNote(),
        ];
    }
}; ?>

<x-layouts.app>
    @volt('selling.earnings')
    <div class="space-y-6">
        @if ($state !== 'ready')
            <x-filament::section heading="Earnings" icon="phosphor-hand-coins" data-earnings-state="{{ $state }}">
                <div class="space-y-4 text-sm text-gray-700">
                    @switch($state)
                        @case('platform')
                            <p>You sell as the platform: revenue stays with {{ config('app.name') }}, so there is no payout account to show. The platform's own Stripe books are in the admin panel under Finance.</p>
                            @break
                        @case('demo')
                            <p>This is a demo account with a made-up Stripe connection, so there is nothing to read from Stripe.</p>
                            @break
                        @default
                            <p>Connect a Stripe account to be paid. Your balance, your payouts to your bank and the transfers for your sales will show up here.</p>
                            <x-button tag="a" href="{{ route('selling.connect') }}" data-ga4-click="{{ \App\Support\Analytics\Ga4::attr('seller_onboarding_started') }}">Connect Stripe</x-button>
                    @endswitch
                </div>
            </x-filament::section>
        @else
            @php
                $main = strtolower((string) config('marketplace.currency', 'usd'));
                $currencyBalance = $balances->first();
                $cards = [
                    ['Available', $currencyBalance ? money($currencyBalance->available_cents, $currencyBalance->currency) : '-', 'in your Stripe balance, paid out to your bank by Stripe', 'available'],
                    ['Pending', $currencyBalance ? money($currencyBalance->pending_cents, $currencyBalance->currency) : '-', 'Stripe releases it a few days after it arrives', 'pending'],
                    ['On its way to your bank', $currencyBalance ? money($currencyBalance->in_transit_cents, $currencyBalance->currency) : '-', 'payouts that left your balance and have not arrived', 'in-transit'],
                    ['Held by '.config('app.name'), money($holding), $upcoming->where('outlook.state', 'held')->count() > 0 ? $upcoming->where('outlook.state', 'held')->count().' on hold for a problem report' : 'paid to you after shipping and the payout delay', 'holding'],
                ];
            @endphp

            <dl class="grid grid-cols-2 gap-6 lg:grid-cols-4" data-earnings-balance>
                @foreach ($cards as [$label, $value, $hint, $key])
                    <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5" data-earnings-card="{{ $key }}">
                        <dt class="text-sm font-medium text-gray-500">{{ $label }}</dt>
                        <dd class="mt-2 text-3xl font-semibold tracking-tight text-gray-950">{{ $value }}</dd>
                        <p class="mt-2 text-sm text-gray-500">{{ $hint }}</p>
                    </div>
                @endforeach
            </dl>

            @foreach ($balances->skip(1) as $other)
                <p class="text-sm text-gray-600" data-earnings-currency="{{ $other->currency }}">{{ strtoupper($other->currency) }}: {{ money($other->available_cents, $other->currency) }} available, {{ money($other->pending_cents, $other->currency) }} pending, {{ money($other->in_transit_cents, $other->currency) }} on its way to your bank.</p>
            @endforeach

            <div class="flex flex-wrap items-center gap-3 text-sm text-gray-500" data-earnings-refreshed>
                @if ($refreshedAt)
                    <span>Balance read from Stripe {{ \Illuminate\Support\Carbon::parse($refreshedAt)->diffForHumans() }}.</span>
                @else
                    <span>Your balance has not been read from Stripe yet.</span>
                @endif
                <x-button wire:click="refreshBalance" wire:loading.attr="disabled" wire:target="refreshBalance" color="gray">Refresh</x-button>
                <span class="text-gray-400">Paid out to your account so far: <span class="font-medium text-gray-700" data-earnings-paid-out>{{ money($paidOut['cents']) }}</span>{{ $paidOut['confirmed'] ? '' : ' (from our records)' }}</span>
            </div>

            <x-filament::section heading="Payouts to your bank" icon="phosphor-bank" description="Stripe pays your available balance out to your bank account on its own schedule." data-earnings-payouts>
                @if ($payouts->isEmpty())
                    <p class="text-sm text-gray-500">No payouts yet. Once money from your sales is available in your Stripe balance, Stripe pays it out to your bank account.</p>
                @else
                    <ul class="divide-y divide-gray-100">
                        @foreach ($payouts as $payout)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm" data-payout="{{ $payout->getKey() }}" data-payout-status="{{ $payout->status }}">
                                <div class="min-w-0">
                                    <p class="font-medium text-gray-950">{{ $payout->destination_label ?: 'Your bank account' }}</p>
                                    <p class="text-gray-500">
                                        @if ($payout->status === 'paid')
                                            Arrived {{ $payout->arrival_date?->format('M j, Y') }}.
                                        @elseif ($payout->isOnTheWay())
                                            Expected {{ $payout->arrival_date?->format('M j, Y') }}.
                                        @elseif ($payout->isFailed())
                                            Sent {{ $payout->stripe_created_at->format('M j, Y') }}, then returned by the bank.
                                        @else
                                            {{ $payout->statusLabel() }} &middot; {{ $payout->stripe_created_at->format('M j, Y') }}
                                        @endif
                                    </p>
                                    @if ($payout->isFailed())
                                        <p class="mt-1 text-red-700" data-payout-failure>{{ $payout->failureExplanation() }}
                                            <a href="{{ route('selling.dashboard-link') }}" class="font-medium underline hover:text-red-900">Open your Stripe dashboard</a></p>
                                    @endif
                                </div>
                                <div class="flex flex-shrink-0 items-center gap-3">
                                    <x-marketplace.badge :color="$payout->statusColor()">{{ $payout->statusLabel() }}</x-marketplace.badge>
                                    <span class="font-semibold text-gray-950">{{ money($payout->amount_cents, $payout->currency) }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>

            <x-filament::section heading="Not paid out to you yet" icon="phosphor-calendar-check" description="What {{ config('app.name') }} still holds for your sales, and when it is released." data-earnings-holding>
                <x-marketplace.upcoming-payouts :upcoming="$upcoming" :note="$payoutNote" />
            </x-filament::section>

            {{ $this->table }}
        @endif
    </div>
    @endvolt
</x-layouts.app>
