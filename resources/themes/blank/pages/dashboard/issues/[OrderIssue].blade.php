<?php

use App\Models\OrderIssue;
use App\Services\Marketplace\PayoutService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use function Laravel\Folio\{middleware, name};

middleware('auth');
name('dashboard.issues.show');

new class extends Component {
    public OrderIssue $issue;

    public function mount(OrderIssue $orderIssue): void
    {
        // OrderIssuePolicy::view: the buyer, the seller or an admin. Everybody else gets a 403.
        Gate::authorize('view', $orderIssue);

        $this->issue = $orderIssue;
    }

    /** The panel changed the issue (offer, refund...): reload what this page shows around it. */
    #[On('issue-changed')]
    public function reload(): void
    {
        $this->issue->refresh();
    }

    public function with(): array
    {
        $issue = $this->issue->load(['order', 'sellerOrder.items', 'sellerOrder.seller.sellerProfile', 'buyer', 'seller.sellerProfile']);
        $user = auth()->user();
        $isBuyer = $issue->isBuyer($user);
        $isSeller = $issue->isSeller($user);
        $sellerOrder = $issue->sellerOrder;

        return [
            'isBuyer' => $isBuyer,
            'isSeller' => $isSeller,
            'isAdmin' => $user->isPlatformSeller(),
            'sellerOrder' => $sellerOrder,
            'shop' => $issue->seller?->sellerProfile,
            'outlook' => $isSeller || $user->isPlatformSeller() ? app(PayoutService::class)->outlook($sellerOrder) : null,
        ];
    }
}; ?>

<x-layouts.app>
    @volt('dashboard.issues.show')
    @php
        $order = $issue->order;
        $partner = $isBuyer ? $issue->seller : $issue->buyer;
        $partnerName = $isBuyer ? ($shop?->shop_name ?: $issue->seller?->name) : $issue->buyer?->name;
    @endphp
    <div class="space-y-6">
        <a href="{{ $isBuyer ? route('dashboard.orders.show', ['order' => $order->number]) : ($isSeller ? route('dashboard.issues') : route('dashboard.issues')) }}" class="inline-flex items-center gap-1 text-sm font-medium text-gray-600 hover:text-gray-900">
            <x-phosphor-arrow-left class="h-4 w-4" /> {{ $isBuyer ? 'Back to order '.$order->number : 'Back to problem reports' }}
        </a>

        <div class="gap-6 lg:grid lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="space-y-6">
                <livewire:order-issue-panel :issue="$issue" :key="'panel-'.$issue->getKey()" />
            </div>

            <aside class="mt-6 space-y-6 lg:mt-0">
                <x-filament::section :heading="'Order '.$order->number" :description="'Placed '.($order->paid_at ?? $order->created_at)->format('F j, Y')">
                    <ul class="-mt-2 divide-y divide-gray-100">
                        @foreach ($sellerOrder->items as $item)
                            <li class="flex items-start justify-between gap-4 py-3 text-sm">
                                <p class="min-w-0 font-medium text-gray-950">{{ $item->quantity > 1 ? $item->quantity.' x ' : '' }}{{ $item->title }}</p>
                                <p class="flex-shrink-0 text-gray-950">{{ money($item->line_total_cents, $order->currency) }}</p>
                            </li>
                        @endforeach
                    </ul>
                    <dl class="space-y-1 border-t border-gray-100 pt-3 text-sm">
                        @if ($sellerOrder->shipping_cents > 0)
                            <div class="flex justify-between text-gray-600"><dt>Shipping</dt><dd>{{ money($sellerOrder->shipping_cents, $order->currency) }}</dd></div>
                        @endif
                        <div class="flex justify-between font-semibold text-gray-950"><dt>Paid for this part</dt><dd>{{ money($sellerOrder->grossCents(), $order->currency) }}</dd></div>
                        @if ($sellerOrder->refunded_cents > 0)
                            <div class="flex justify-between text-amber-800"><dt>Refunded</dt><dd>{{ money($sellerOrder->refunded_cents, $order->currency) }}</dd></div>
                        @endif
                    </dl>
                    <p class="mt-3 text-sm">
                        <x-marketplace.badge :color="$sellerOrder->status->color()">{{ $sellerOrder->status->label() }}</x-marketplace.badge>
                    </p>
                    @if (filled($sellerOrder->tracking_number))
                        <p class="mt-3 text-sm text-gray-700">
                            <x-phosphor-truck class="mr-1 inline h-4 w-4 align-text-bottom" />
                            {{ $sellerOrder->carrier }} <span class="font-mono">{{ $sellerOrder->tracking_number }}</span>
                            @if (filled($sellerOrder->tracking_url))
                                &middot; <a href="{{ $sellerOrder->tracking_url }}" target="_blank" rel="noopener nofollow" class="font-medium text-brand-700 underline hover:text-brand-900">Track</a>
                            @endif
                        </p>
                    @endif
                </x-filament::section>

                <x-filament::section :heading="$isBuyer ? 'The seller' : 'The buyer'">
                    <p class="text-sm font-medium text-gray-950">{{ $partnerName }}</p>
                    @if ($partner && ! $partner->is(auth()->user()))
                        <x-button tag="a" href="{{ url('/chatify/'.$partner->getKey()) }}" class="mt-3">
                            <x-phosphor-chat-circle class="mr-1 inline h-4 w-4" /> {{ $isBuyer ? 'Contact seller' : 'Contact buyer' }}
                        </x-button>
                    @endif
                    @if ($isAdmin)
                        <p class="mt-3 text-sm text-gray-600">
                            Seller: <span class="font-medium text-gray-950">{{ $shop?->shop_name ?: $issue->seller?->name }}</span>
                        </p>
                        <a href="{{ \App\Filament\Resources\OrderIssueResource::getUrl('view', ['record' => $issue]) }}" class="mt-3 inline-block text-sm font-medium text-brand-700 underline hover:text-brand-900">Open in the admin panel</a>
                    @endif
                </x-filament::section>

                @if ($outlook && $outlook['state'] !== 'none')
                    <x-filament::section heading="Payout" data-payout-state="{{ $outlook['state'] }}">
                        <dl class="space-y-1 text-sm">
                            <div class="flex justify-between font-semibold text-gray-950"><dt>{{ $isSeller ? 'Your payout' : 'Seller payout' }}</dt><dd>{{ money($sellerOrder->netPayoutCents(), $order->currency) }}</dd></div>
                        </dl>
                        <p class="mt-2 text-sm text-gray-600">
                            @switch($outlook['state'])
                                @case('paid_out') Transferred {{ $outlook['at']?->format('M j, Y') }}. @break
                                @case('held') On hold until this problem is resolved. @break
                                @case('scheduled') Released {{ $outlook['at']->format('M j, Y') }} unless a problem is open. @break
                                @default Released after the parcel is shipped.
                            @endswitch
                        </p>
                    </x-filament::section>
                @endif
            </aside>
        </div>
    </div>
    @endvolt
</x-layouts.app>
