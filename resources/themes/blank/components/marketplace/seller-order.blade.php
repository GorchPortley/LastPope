{{--
    One seller's part of an order, as the buyer (or an admin / that seller) sees it: items, shipping, status,
    tracking, and the "Confirm received" button for the buyer once it shipped. Drawn as a Filament section so
    it sits next to the dashboard tables.
--}}
@props(['sellerOrder', 'order'])

@php
    $so = $sellerOrder;
    $shop = $so->seller?->sellerProfile;
    $shopName = $shop?->shop_name ?: ($so->seller?->name ?? 'Unknown seller');
    $canConfirm = auth()->check()
        && $so->status === \App\Enums\SellerOrderStatus::SHIPPED
        && auth()->user()->can('confirmReceived', $so)
        && (int) $order->user_id === (int) auth()->id();
@endphp

<x-filament::section {{ $attributes }} data-seller-order="{{ $so->getKey() }}">
    <x-slot name="heading">
        Sold by
        @if ($shop)
            <a href="{{ route('seller', ['slug' => $shop->slug]) }}" class="hover:underline">{{ $shopName }}</a>
        @else
            {{ $shopName }}
        @endif
    </x-slot>
    <x-slot name="headerEnd">
        <x-marketplace.badge :color="$so->status->color()">{{ $so->status->label() }}</x-marketplace.badge>
    </x-slot>

    <ul class="-mt-2 divide-y divide-gray-100">
        @foreach ($so->items as $item)
            <li class="flex items-start justify-between gap-4 py-3 text-sm">
                <div class="min-w-0">
                    <p class="font-medium text-gray-950">{{ $item->quantity > 1 ? $item->quantity.' x ' : '' }}{{ $item->title }}</p>
                    <p class="text-gray-500">
                        {{ $item->is_digital ? 'Digital download' : 'Physical item' }}
                        @if ($item->is_digital && $item->entitlement?->isActive())
                            &middot; <a href="{{ route('dashboard.library') }}" class="underline">In your library</a>
                        @endif
                    </p>
                </div>
                <p class="flex-shrink-0 font-medium text-gray-950">{{ money($item->line_total_cents, $order->currency) }}</p>
            </li>
        @endforeach
    </ul>

    <dl class="space-y-1 border-t border-gray-100 pt-3 text-sm">
        @if ($so->shipping_cents > 0)
            <div class="flex justify-between text-gray-600">
                <dt>Shipping</dt>
                <dd>{{ money($so->shipping_cents, $order->currency) }}</dd>
            </div>
        @endif
        <div class="flex justify-between font-semibold text-gray-950">
            <dt>Subtotal from this seller</dt>
            <dd>{{ money($so->grossCents(), $order->currency) }}</dd>
        </div>
    </dl>

    @if ($so->status === \App\Enums\SellerOrderStatus::AWAITING_SHIPMENT)
        <p class="mt-3 border-t border-gray-100 pt-3 text-sm text-gray-600">
            <x-phosphor-package class="mr-1 inline h-4 w-4 align-text-bottom" /> Waiting for the seller to ship. You get an e-mail with the tracking details.
        </p>
    @elseif (in_array($so->status, [\App\Enums\SellerOrderStatus::SHIPPED, \App\Enums\SellerOrderStatus::DELIVERED, \App\Enums\SellerOrderStatus::COMPLETED], true) && filled($so->tracking_number))
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-3 text-sm">
            <p class="min-w-0 text-gray-700">
                <x-phosphor-truck class="mr-1 inline h-4 w-4 align-text-bottom" />
                Shipped {{ $so->shipped_at?->format('M j') }} with <strong>{{ $so->carrier }}</strong>,
                tracking <span class="font-mono">{{ $so->tracking_number }}</span>
            </p>
            @if (filled($so->tracking_url))
                <a href="{{ $so->tracking_url }}" target="_blank" rel="noopener nofollow" class="font-medium text-brand-700 underline hover:text-brand-900">Track parcel</a>
            @endif
        </div>
    @elseif ($so->status === \App\Enums\SellerOrderStatus::REFUNDED)
        <p class="mt-3 border-t border-gray-100 pt-3 text-sm text-amber-800">
            Refunded {{ money($so->refunded_cents, $order->currency) }}{{ $so->refunded_at ? ' on '.$so->refunded_at->format('M j, Y') : '' }}.
        </p>
    @endif

    @if ($canConfirm)
        <form method="POST" action="{{ route('orders.received', $so) }}" class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-green-50 px-4 py-3">
            @csrf
            <p class="text-sm text-green-900">Did it arrive? Confirming completes the order and releases the payment to the seller.</p>
            <x-button type="submit" color="success" size="sm">Confirm received</x-button>
        </form>
    @endif
</x-filament::section>
