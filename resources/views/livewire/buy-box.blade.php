@php
    use App\Models\Design;

    $p = $purchasable;
    $lotWord = $state['lot'];
    $kindWord = match (true) {
        $p instanceof Design => 'design',
        default => 'listing',
    };
@endphp

<div class="grid w-full gap-2.5 border border-rule bg-paper p-3.5" data-buy-box="{{ $state['status'] }}">
    @if ($label)
        <p class="sd-label">{{ $label }}</p>
    @endif

    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <p @class(['font-condensed text-[34px] font-bold leading-none', 'text-green-700' => $state['free'], 'text-ink' => ! $state['free']])>
            {{ $state['free'] ? 'Free' : money($state['price']) }}
            @if ($lotWord)
                <span class="font-sans text-sm font-normal text-ink-2">per {{ $lotWord }}</span>
            @endif
        </p>
        @if (! $state['digital'] && in_array($state['status'], ['add', 'login', 'owner'], true))
            <p class="text-sm font-medium {{ $state['stockWarn'] ? 'text-amber-700' : 'text-green-700' }}" data-buy-box-stock>{{ $state['stockText'] }}</p>
        @endif
    </div>

    @if ($state['shipping'])
        <p class="flex items-start gap-2 text-sm text-ink-2" data-buy-box-shipping>
            <x-phosphor-truck class="mt-0.5 h-4 w-4 flex-shrink-0" />
            <span>
                Ships from {{ $state['shipping']['from'] }}{{ $state['shipping']['handling'] ? ' within '.$state['shipping']['handling'] : '' }}.
                Shipping {{ $state['shipping']['domestic'] }} in-country, {{ $state['shipping']['international'] }} international.
            </span>
        </p>
    @endif

    <div class="grid gap-2.5">
        @switch($state['status'])
            @case('owner')
                <x-marketplace.notice level="info">This is your own {{ $kindWord }}.</x-marketplace.notice>
                @if ($state['downloadUrl'])
                    <a href="{{ $state['downloadUrl'] }}" class="sd-btn sd-btn-go w-full" data-ga4-click="{{ \App\Support\Analytics\Ga4::download($p, 'latest') }}">Download latest files</a>
                @endif
                @break

            @case('owned')
                <div class="flex items-center gap-2 font-medium text-green-700">
                    <x-phosphor-check-circle class="h-5 w-5" /> In your library
                </div>
                @if ($state['downloadUrl'])
                    <a href="{{ $state['downloadUrl'] }}" class="sd-btn sd-btn-go w-full" data-ga4-click="{{ \App\Support\Analytics\Ga4::download($p, 'latest') }}">Download</a>
                @endif
                <a href="{{ route('dashboard.library') }}" class="block text-center text-sm text-brand-800 underline hover:text-brand-900">Open my library</a>
                @break

            @case('revoked')
                <button type="button" class="sd-btn w-full opacity-50 hover:bg-white hover:text-ink" disabled>Not available</button>
                <p class="text-sm text-ink-2">Your access to this {{ $kindWord }} was withdrawn. Contact support if this is a mistake.</p>
                @break

            @case('unavailable')
                <button type="button" class="sd-btn w-full opacity-50 hover:bg-white hover:text-ink" disabled>{{ $state['digital'] ? 'Not available' : (str_contains((string) $state['reason'], 'Sold out') ? 'Sold out' : 'Not available') }}</button>
                <p class="text-sm text-ink-2">{{ $state['reason'] }}</p>
                @break

            @case('login')
                <a href="{{ route('login') }}" class="sd-btn sd-btn-go w-full">{{ $state['free'] ? 'Log in to get it for free' : 'Log in to buy' }}</a>
                @break

            @case('free')
                <button type="button" class="sd-btn sd-btn-go w-full disabled:opacity-60" wire:click="add" wire:loading.attr="disabled" wire:target="add">
                    <span wire:loading.remove wire:target="add">Get for free</span>
                    <span wire:loading wire:target="add">Adding...</span>
                </button>
                <p class="text-[13px] text-ink-2">Free items go straight to your library.</p>
                @break

            @case('in_cart')
                <a href="{{ route('cart') }}" class="sd-btn w-full">
                    <x-phosphor-shopping-cart class="inline h-4 w-4" /> In your cart - view cart
                </a>
                @break

            @default
                @if (! $state['digital'])
                    <div class="flex items-center gap-3">
                        <label for="qty-{{ $this->getId() }}" class="sd-label">Quantity</label>
                        <input id="qty-{{ $this->getId() }}" type="number" min="1" max="{{ $state['max'] }}" wire:model.live.debounce.300ms="quantity"
                               class="sd-num w-24 rounded-none border-rule text-sm text-ink focus:border-ink focus:ring-0">
                    </div>
                @endif
                <button type="button" class="sd-btn sd-btn-go w-full disabled:opacity-60" wire:click="add" wire:loading.attr="disabled" wire:target="add">
                    <span wire:loading.remove wire:target="add">Add to cart</span>
                    <span wire:loading wire:target="add">Adding...</span>
                </button>
                @if ($state['inCart'] > 0)
                    <p class="text-sm text-ink-2">
                        {{ $state['inCart'] }} already in your cart.
                        <a href="{{ route('cart') }}" class="text-brand-800 underline hover:text-brand-900">View cart</a>
                    </p>
                @endif
        @endswitch

        @if ($notice)
            <x-marketplace.notice :level="$noticeLevel">{{ $notice }}</x-marketplace.notice>
        @endif
    </div>
</div>
