{{--
    One seller's part of an order, as the buyer (or an admin / that seller) sees it: items, shipping, status,
    tracking, and the "Confirm received" button for the buyer once it shipped.
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

<div {{ $attributes->class(['border border-gray-200 bg-white dark:border-zinc-700 dark:bg-zinc-800']) }} data-seller-order="{{ $so->getKey() }}">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900">
        <p class="text-sm text-gray-600 dark:text-zinc-400">
            Sold by
            @if ($shop)
                <a href="{{ route('seller', ['slug' => $shop->slug]) }}" class="font-semibold text-gray-900 hover:underline dark:text-white">{{ $shopName }}</a>
            @else
                <span class="font-semibold text-gray-900 dark:text-white">{{ $shopName }}</span>
            @endif
        </p>
        <x-marketplace.badge :color="$so->status->color()">{{ $so->status->label() }}</x-marketplace.badge>
    </div>

    <ul class="divide-y divide-gray-100 dark:divide-zinc-700">
        @foreach ($so->items as $item)
            <li class="flex items-start justify-between gap-4 px-4 py-3 text-sm">
                <div class="min-w-0">
                    <p class="font-medium text-gray-900 dark:text-white">{{ $item->quantity > 1 ? $item->quantity.' x ' : '' }}{{ $item->title }}</p>
                    <p class="text-gray-500 dark:text-zinc-400">
                        {{ $item->is_digital ? 'Digital download' : 'Physical item' }}
                        @if ($item->is_digital && $item->entitlement?->isActive())
                            &middot; <a href="{{ route('dashboard.library') }}" class="underline">In your library</a>
                        @endif
                    </p>
                </div>
                <p class="flex-shrink-0 font-medium text-gray-900 dark:text-white">{{ money($item->line_total_cents, $order->currency) }}</p>
            </li>
        @endforeach
    </ul>

    <dl class="space-y-1 border-t border-gray-100 px-4 py-3 text-sm dark:border-zinc-700">
        @if ($so->shipping_cents > 0)
            <div class="flex justify-between text-gray-600 dark:text-zinc-400">
                <dt>Shipping</dt>
                <dd>{{ money($so->shipping_cents, $order->currency) }}</dd>
            </div>
        @endif
        <div class="flex justify-between font-semibold text-gray-900 dark:text-white">
            <dt>Subtotal from this seller</dt>
            <dd>{{ money($so->grossCents(), $order->currency) }}</dd>
        </div>
    </dl>

    @if ($so->status === \App\Enums\SellerOrderStatus::AWAITING_SHIPMENT)
        <p class="border-t border-gray-100 px-4 py-3 text-sm text-gray-600 dark:border-zinc-700 dark:text-zinc-400">
            <x-phosphor-package class="mr-1 inline h-4 w-4 align-text-bottom" /> Waiting for the seller to ship. You get an e-mail with the tracking details.
        </p>
    @elseif (in_array($so->status, [\App\Enums\SellerOrderStatus::SHIPPED, \App\Enums\SellerOrderStatus::DELIVERED, \App\Enums\SellerOrderStatus::COMPLETED], true) && filled($so->tracking_number))
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 px-4 py-3 text-sm dark:border-zinc-700">
            <p class="min-w-0 text-gray-700 dark:text-zinc-300">
                <x-phosphor-truck class="mr-1 inline h-4 w-4 align-text-bottom" />
                Shipped {{ $so->shipped_at?->format('M j') }} with <strong>{{ $so->carrier }}</strong>,
                tracking <span class="font-mono">{{ $so->tracking_number }}</span>
            </p>
            @if (filled($so->tracking_url))
                <a href="{{ $so->tracking_url }}" target="_blank" rel="noopener nofollow" class="font-medium text-purple-700 underline hover:text-purple-900 dark:text-purple-300">Track parcel</a>
            @endif
        </div>
    @elseif ($so->status === \App\Enums\SellerOrderStatus::REFUNDED)
        <p class="border-t border-gray-100 px-4 py-3 text-sm text-amber-800 dark:border-zinc-700 dark:text-amber-400">
            Refunded {{ money($so->refunded_cents, $order->currency) }}{{ $so->refunded_at ? ' on '.$so->refunded_at->format('M j, Y') : '' }}.
        </p>
    @endif

    @if ($canConfirm)
        <form method="POST" action="{{ route('orders.received', $so) }}" class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 bg-green-50 px-4 py-3 dark:border-zinc-700 dark:bg-green-950">
            @csrf
            <p class="text-sm text-green-900 dark:text-green-200">Did it arrive? Confirming completes the order and releases the payment to the seller.</p>
            <x-button type="submit" color="success" size="sm">Confirm received</x-button>
        </form>
    @endif
</div>
