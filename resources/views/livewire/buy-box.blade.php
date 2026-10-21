@php
    use App\Models\Design;

    $p = $purchasable;
    $lotWord = $state['lot'];
    $kindWord = match (true) {
        $p instanceof Design => 'design',
        default => 'listing',
    };
@endphp

<x-marketplace.box class="w-full" data-buy-box="{{ $state['status'] }}">
    @if ($label)
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $label }}</p>
    @endif

    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <p class="text-3xl font-bold text-gray-900">
            {{ $state['free'] ? 'Free' : money($state['price']) }}
            @if ($lotWord)
                <span class="text-sm font-normal text-gray-500">per {{ $lotWord }}</span>
            @endif
        </p>
        @if (! $state['digital'] && in_array($state['status'], ['add', 'login', 'owner'], true))
            <p class="text-sm font-medium {{ $state['max'] <= 3 ? 'text-amber-700' : 'text-green-700' }}" data-buy-box-stock>{{ $state['max'] }} available</p>
        @endif
    </div>

    @if ($state['shipping'])
        <p class="mt-2 flex items-start gap-2 text-sm text-gray-600" data-buy-box-shipping>
            <x-phosphor-truck class="mt-0.5 h-4 w-4 flex-shrink-0" />
            <span>
                Ships from {{ $state['shipping']['from'] }} within {{ $state['shipping']['handling'] }}.
                Shipping {{ $state['shipping']['domestic'] }} in-country, {{ $state['shipping']['international'] }} international.
            </span>
        </p>
    @endif

    <div class="mt-4 space-y-3">
        @switch($state['status'])
            @case('owner')
                <x-marketplace.notice level="info">This is your own {{ $kindWord }}.</x-marketplace.notice>
                @if ($state['downloadUrl'])
                    <x-button tag="a" href="{{ $state['downloadUrl'] }}" class="w-full">Download latest files</x-button>
                @endif
                @break

            @case('owned')
                <div class="flex items-center gap-2 font-medium text-green-700">
                    <x-phosphor-check-circle class="h-5 w-5" /> In your library
                </div>
                @if ($state['downloadUrl'])
                    <x-button tag="a" href="{{ $state['downloadUrl'] }}" class="w-full">Download</x-button>
                @endif
                <a href="{{ route('dashboard.library') }}" class="block text-center text-sm text-brand-700 underline hover:text-brand-900">Open my library</a>
                @break

            @case('revoked')
                <x-button class="w-full" color="gray" disabled>Not available</x-button>
                <p class="text-sm text-gray-600">Your access to this {{ $kindWord }} was withdrawn. Contact support if this is a mistake.</p>
                @break

            @case('unavailable')
                <x-button class="w-full" color="gray" disabled>{{ $state['digital'] ? 'Not available' : (str_contains((string) $state['reason'], 'Sold out') ? 'Sold out' : 'Not available') }}</x-button>
                <p class="text-sm text-gray-600">{{ $state['reason'] }}</p>
                @break

            @case('login')
                <x-button tag="a" href="{{ route('login') }}" class="w-full">{{ $state['free'] ? 'Log in to get it for free' : 'Log in to buy' }}</x-button>
                @break

            @case('free')
                <x-button class="w-full" color="success" wire:click="add" wire:loading.attr="disabled" wire:target="add">
                    <span wire:loading.remove wire:target="add">Get for free</span>
                    <span wire:loading wire:target="add">Adding...</span>
                </x-button>
                <p class="text-xs text-gray-500">Free items go straight to your library.</p>
                @break

            @case('in_cart')
                <x-button tag="a" href="{{ route('cart') }}" class="w-full" color="gray">
                    <x-phosphor-shopping-cart class="mr-1 inline h-4 w-4" /> In your cart - view cart
                </x-button>
                @break

            @default
                @if (! $state['digital'])
                    <div class="flex items-center gap-3">
                        <label for="qty-{{ $this->getId() }}" class="text-sm font-medium text-gray-700">Quantity</label>
                        <input id="qty-{{ $this->getId() }}" type="number" min="1" max="{{ $state['max'] }}" wire:model="quantity"
                               class="w-24 border-gray-300 text-sm">
                    </div>
                @endif
                <x-button class="w-full" wire:click="add" wire:loading.attr="disabled" wire:target="add">
                    <span wire:loading.remove wire:target="add">Add to cart</span>
                    <span wire:loading wire:target="add">Adding...</span>
                </x-button>
                @if ($state['inCart'] > 0)
                    <p class="text-sm text-gray-600">
                        {{ $state['inCart'] }} already in your cart.
                        <a href="{{ route('cart') }}" class="text-brand-700 underline hover:text-brand-900">View cart</a>
                    </p>
                @endif
        @endswitch

        @if ($notice)
            <x-marketplace.notice :level="$noticeLevel">{{ $notice }}</x-marketplace.notice>
        @endif
    </div>
</x-marketplace.box>
