{{-- Admin > Finance > Stripe ledger: the platform's Stripe balance, then every balance transaction (App\Models\StripeBalanceTransaction). --}}
@php
    $balances = $this->balances();
    $cursor = $this->platformCursor();
    $failing = $this->failingAccounts();
    $live = \App\Support\StripeMode::isLive();
@endphp

<x-filament-panels::page>
    <div class="space-y-1 text-sm text-gray-500 dark:text-gray-400" data-ledger-status>
        <p>
            @if ($cursor?->hasSynced())
                Read from Stripe <span class="font-medium text-gray-950 dark:text-white" title="{{ $cursor->last_synced_at?->format('M j, Y H:i') }}">{{ $cursor->last_synced_at?->diffForHumans() }}</span>
                ({{ $live ? 'live' : 'test' }} mode). It is read again every ten minutes and when Stripe says something changed.
            @else
                Not read from Stripe yet ({{ $live ? 'live' : 'test' }} mode). Press "Sync now", or run <code>php artisan stripe:ledger-sync --all</code> once to read the whole history.
            @endif
        </p>
        @if ($cursor?->isFailing())
            <p class="font-medium text-danger-600 dark:text-danger-400" data-ledger-error>The last attempt failed {{ $cursor->last_error_at?->diffForHumans() }}: {{ $cursor->last_error }}</p>
        @endif
        @foreach ($failing as $failed)
            <p class="text-danger-600 dark:text-danger-400" data-ledger-error-account="{{ $failed->account_key }}">Seller account {{ $failed->account_key }} could not be read ({{ $failed->last_error_at?->diffForHumans() }}): {{ $failed->last_error }}</p>
        @endforeach
    </div>

    @if ($balances->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400" data-ledger-no-balance>The platform balance is shown after the first sync.</p>
    @endif

    @foreach ($balances as $balance)
        @php
            $currency = $balance->currency;
            $cards = [
                ['Available', $balance->available_cents, 'can be paid out or transferred now', 'available'],
                ['Pending', $balance->pending_cents, 'not available yet: Stripe releases card payments a few days after the sale', 'pending'],
                ['On its way to the bank', $balance->in_transit_cents, 'payouts that have left the balance and not arrived', 'in-transit'],
            ];
            if ($balance->connect_reserved_cents > 0) {
                $cards[] = ['Reserved for Connect', $balance->connect_reserved_cents, 'held by Stripe for the connected accounts', 'reserved'];
            }
        @endphp
        <div data-balance-currency="{{ $currency }}">
            @if ($balances->count() > 1)
                <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ strtoupper($currency) }}</h3>
            @endif
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($cards as [$label, $cents, $note, $key])
                    <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" data-balance-card="{{ $key }}">
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight {{ $cents < 0 ? 'text-danger-600 dark:text-danger-400' : 'text-gray-950 dark:text-white' }}">{{ money($cents, $currency) }}</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $note }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    {{ $this->table }}
</x-filament-panels::page>
