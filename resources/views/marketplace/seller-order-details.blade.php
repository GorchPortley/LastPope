{{-- Body of the "Details" modal of a sale (seller dashboard). $sellerOrder: SellerOrder with order + items loaded. --}}
@php
    $order = $sellerOrder->order;
    $address = $order->shipping_address;
@endphp

<div class="space-y-5 text-sm">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <p class="font-semibold text-gray-900 dark:text-white">Order {{ $order->number }}</p>
            <p class="text-gray-500 dark:text-gray-400">{{ ($order->paid_at ?? $order->created_at)->format('M j, Y H:i') }}</p>
        </div>
        <x-marketplace.badge :color="$sellerOrder->status->color()">{{ $sellerOrder->status->label() }}</x-marketplace.badge>
    </div>

    <ul class="divide-y divide-gray-200 border-y border-gray-200 dark:divide-white/10 dark:border-white/10">
        @foreach ($sellerOrder->items as $item)
            <li class="flex justify-between gap-4 py-2">
                <span>{{ $item->quantity > 1 ? $item->quantity.' x ' : '' }}{{ $item->title }} <span class="text-gray-500">({{ $item->is_digital ? 'digital' : 'physical' }})</span></span>
                <span class="font-medium">{{ money($item->line_total_cents, $order->currency) }}</span>
            </li>
        @endforeach
    </ul>

    <dl class="space-y-1">
        <div class="flex justify-between"><dt class="text-gray-500">Items</dt><dd>{{ money($sellerOrder->subtotal_cents, $order->currency) }}</dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Shipping paid by the buyer</dt><dd>{{ money($sellerOrder->shipping_cents, $order->currency) }}</dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Platform fee</dt><dd>- {{ money($sellerOrder->platform_fee_cents, $order->currency) }}</dd></div>
        <div class="flex justify-between border-t border-gray-200 pt-1 font-semibold dark:border-white/10"><dt>Your payout</dt><dd>{{ money($sellerOrder->payout_cents, $order->currency) }}</dd></div>
        <p class="pt-1 text-xs text-gray-500">{{ $sellerOrder->isTransferred() ? 'Transferred '.$sellerOrder->transferred_at->format('M j, Y') : ($sellerOrder->payout_cents > 0 ? 'Released '.(config('marketplace.physical_payout_trigger') === 'shipped' ? 'when you mark physical items as shipped.' : 'after payment.') : 'No payout: you sell as the platform.') }}</p>
    </dl>

    @if ($sellerOrder->hasPhysicalItems() && filled($address))
        <div>
            <p class="mb-1 font-semibold">Ship to</p>
            <address class="not-italic text-gray-700 dark:text-gray-300">
                {{ $address['name'] ?? '' }}<br>
                {{ $address['line1'] ?? '' }}@if (filled($address['line2'] ?? null)), {{ $address['line2'] }}@endif<br>
                {{ $address['postal_code'] ?? '' }} {{ $address['city'] ?? '' }}@if (filled($address['state'] ?? null)), {{ $address['state'] }}@endif<br>
                {{ \App\Support\Countries::name($address['country'] ?? null) }}
                @if (filled($address['phone'] ?? null))<br>Phone: {{ $address['phone'] }}@endif
            </address>
        </div>
    @endif

    @if (filled($sellerOrder->tracking_number))
        <p>
            <span class="font-semibold">Tracking:</span> {{ $sellerOrder->carrier }} <span class="font-mono">{{ $sellerOrder->tracking_number }}</span>
            @if (filled($sellerOrder->tracking_url))<a href="{{ $sellerOrder->tracking_url }}" target="_blank" rel="noopener nofollow" class="ml-2 underline">Track</a>@endif
        </p>
    @endif
</div>
