{{--
    One seller's part of an order, as the buyer (or an admin / that seller) sees it: items, shipping, status,
    tracking, the "Confirm received" button for the buyer once it shipped, and the problem report ("Report a problem"
    while the window is open, then the report's panel). Drawn as a Filament section so it sits next to the dashboard tables.

    $problem: ['issue' => latest OrderIssue|null, 'canReport' => bool, 'blocker' => ?string, 'windowEnds' => ?Carbon]
    The `reportButton` slot holds the page's "Report a problem" action.
--}}
@props(['sellerOrder', 'order', 'problem' => ['issue' => null, 'canReport' => false, 'blocker' => null, 'windowEnds' => null]])

@php
    $so = $sellerOrder;
    $shop = $so->seller?->sellerProfile;
    $shopName = $shop?->shop_name ?: ($so->seller?->name ?? 'Unknown seller');
    $issue = $problem['issue'];
    $openIssue = $issue && $issue->isUnresolved();
    $isBuyerView = auth()->check() && (int) $order->user_id === (int) auth()->id();
    $contact = $isBuyerView ? $so->seller : $order->user;
    $canConfirm = auth()->check()
        && $so->status === \App\Enums\SellerOrderStatus::SHIPPED
        && auth()->user()->can('confirmReceived', $so)
        && $isBuyerView
        && ! $openIssue;
    $payouts = app(\App\Services\Marketplace\PayoutService::class);
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

    @php($shipments = $so->shipments()->with('items.orderItem')->get())
    {{-- The parcels of a physical part of the order: own stock / each supplier (never named), see ParcelPlanner. --}}
    @php($plan = $so->hasPhysicalItems() ? app(\App\Services\Marketplace\ParcelPlanner::class)->parcels($so) : collect())
    @php($several = ! in_array($so->status, [\App\Enums\SellerOrderStatus::PENDING, \App\Enums\SellerOrderStatus::REFUNDED, \App\Enums\SellerOrderStatus::CANCELLED], true)
        && ($plan->count() > 1 || $shipments->count() > 1 || ($so->status === \App\Enums\SellerOrderStatus::PARTIALLY_SHIPPED && $shipments->isNotEmpty())))
    @if ($so->status->awaitsShipment())
        <p class="mt-3 border-t border-gray-100 pt-3 text-sm text-gray-600">
            <x-phosphor-package class="mr-1 inline h-4 w-4 align-text-bottom" />
            @if ($shipments->isEmpty())
                Waiting for the seller to ship. You get an e-mail with the tracking details.
                @if (! $several && ($expected = $plan->first()?->expectedText($order->paid_at)))
                    <span data-expected-shipping>{{ $expected }}.</span>
                @endif
            @else
                {{ $shipments->count() }} {{ \Illuminate\Support\Str::plural('parcel', $shipments->count()) }} shipped; the rest of this part ships separately. You get an e-mail for every parcel.
            @endif
        </p>
    @endif
    @if ($several)
        <ul class="mt-3 divide-y divide-gray-100 border-t border-gray-100 text-sm" data-parcels>
            @foreach ($plan as $parcel)
                <li class="py-3" data-parcel="{{ $parcel->key }}">
                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                        <p class="min-w-0 font-medium text-gray-950">
                            <x-phosphor-package class="mr-1 inline h-4 w-4 align-text-bottom" />
                            Parcel {{ $loop->iteration }} of {{ $plan->count() }}
                            <span class="font-normal text-gray-500">&middot; {{ $parcel->label() }}</span>
                        </p>
                        <x-marketplace.badge :color="$parcel->isShipped() ? 'success' : ($parcel->isPartlyShipped() ? 'info' : 'warning')" data-parcel-status>
                            {{ $parcel->isShipped() ? 'Shipped' : ($parcel->isPartlyShipped() ? 'Partly shipped' : 'Not shipped yet') }}
                        </x-marketplace.badge>
                    </div>
                    <p class="mt-1 text-gray-600">{{ $parcel->items->map(fn ($item) => ($item->quantity > 1 ? $item->quantity.' x ' : '').$item->title)->implode(', ') }}</p>
                    @foreach ($parcel->shipments as $shipment)
                        <div class="mt-1 flex flex-wrap items-center justify-between gap-3 text-gray-700">
                            <p class="min-w-0">
                                <x-phosphor-truck class="mr-1 inline h-4 w-4 align-text-bottom" />
                                Shipped {{ $shipment->shipped_at?->format('M j') }}{{ filled($shipment->carrier) ? ' with ' : '' }}@if (filled($shipment->carrier))<strong>{{ $shipment->carrier }}</strong>@endif{{ filled($shipment->tracking_number) ? ', tracking ' : '' }}@if (filled($shipment->tracking_number))<span class="font-mono">{{ $shipment->tracking_number }}</span>@endif
                            </p>
                            @if (filled($shipment->tracking_url))
                                <a href="{{ $shipment->tracking_url }}" target="_blank" rel="noopener nofollow" class="font-medium text-brand-700 underline hover:text-brand-900">Track parcel</a>
                            @endif
                        </div>
                    @endforeach
                    @if (! $parcel->isShipped() && $so->status->awaitsShipment())
                        <p class="mt-1 text-gray-500" data-expected-shipping>
                            {{ $parcel->expectedText($order->paid_at) ?? ($parcel->isPartlyShipped() ? 'The rest of this parcel ships soon.' : 'Being prepared for shipment.') }}
                        </p>
                    @endif
                </li>
            @endforeach
        </ul>
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

    @if ($so->refunded_cents > 0 && $so->status !== \App\Enums\SellerOrderStatus::REFUNDED)
        <p class="mt-3 border-t border-gray-100 pt-3 text-sm text-amber-800" data-partial-refund>
            {{ money($so->refunded_cents, $order->currency) }} of this part was refunded{{ $so->refunded_at ? ' on '.$so->refunded_at->format('M j, Y') : '' }}.
        </p>
    @endif

    @if ($canConfirm)
        <form method="POST" action="{{ route('orders.received', $so) }}" class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-green-50 px-4 py-3">
            @csrf
            <p class="text-sm text-green-900">Did it arrive? Confirming completes the order; it does not pay the seller any sooner. @if ($payouts->trigger() === 'shipped_delay')The seller is paid {{ $payouts->delayDays() }} {{ \Illuminate\Support\Str::plural('day', $payouts->delayDays()) }} after shipping, so you can still report a problem until then.@endif</p>
            <x-button type="submit" color="success" size="sm">Confirm received</x-button>
        </form>
    @endif

    {{-- Problem report --}}
    @if ($openIssue)
        <div class="mt-3 border-t border-gray-100 pt-3" data-problem="open">
            <livewire:order-issue-panel :issue="$issue" :key="'issue-'.$issue->getKey()" />
        </div>
    @elseif ($issue)
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-3 text-sm" data-problem="resolved">
            <p class="min-w-0 text-gray-700">
                <x-phosphor-check-circle class="mr-1 inline h-4 w-4 align-text-bottom text-green-600" />
                Problem &quot;{{ $issue->reason->shortLabel() }}&quot; resolved{{ $issue->resolved_at ? ' on '.$issue->resolved_at->format('M j, Y') : '' }}:
                <strong>{{ $issue->resolution?->label() }}</strong>{{ $issue->refunded_cents > 0 ? ', '.money($issue->refunded_cents, $order->currency).' refunded' : '' }}.
            </p>
            <a href="{{ $issue->url() }}" class="font-medium text-brand-700 underline hover:text-brand-900">View</a>
        </div>
    @endif

    @if ($problem['canReport'] && ! $openIssue)
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-3" data-problem="report">
            <p class="min-w-0 text-sm text-gray-600">
                Something wrong with this part of your order?
                @if ($problem['windowEnds'])
                    You can report a problem until {{ $problem['windowEnds']->format('M j, Y') }}.
                @else
                    You can report it at any time until the seller ships.
                @endif
            </p>
            {{ $reportButton ?? '' }}
        </div>
    @elseif ($isBuyerView && ! $openIssue && ! $issue && filled($problem['blocker']) && in_array($so->status, [\App\Enums\SellerOrderStatus::SHIPPED, \App\Enums\SellerOrderStatus::DELIVERED, \App\Enums\SellerOrderStatus::COMPLETED], true))
        <p class="mt-3 border-t border-gray-100 pt-3 text-sm text-gray-500" data-problem="closed">{{ $problem['blocker'] }}</p>
    @endif

    @if ($contact && ! $contact->is(auth()->user()) && $so->status !== \App\Enums\SellerOrderStatus::PENDING)
        <div class="mt-3 flex flex-wrap items-center gap-3 border-t border-gray-100 pt-3 text-sm">
            <a href="{{ url('/chatify/'.$contact->getKey()) }}" class="inline-flex items-center gap-1 font-medium text-gray-700 hover:text-gray-950" data-contact>
                <x-phosphor-chat-circle class="h-4 w-4" /> {{ $isBuyerView ? 'Contact seller' : 'Contact buyer' }}
            </a>
        </div>
    @endif
</x-filament::section>
