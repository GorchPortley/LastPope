<?php

use App\Models\Order;
use App\Support\Countries;
use Illuminate\Support\Facades\Gate;
use Livewire\Volt\Component;
use function Laravel\Folio\{middleware, name};

middleware('auth');
name('dashboard.orders.show');

new class extends Component {
    public Order $order;

    public function mount(Order $order): void
    {
        // OrderPolicy::view: the buyer, a seller with items in it, or an admin.
        Gate::authorize('view', $order);

        $this->order = $order->load(['sellerOrders.seller.sellerProfile', 'sellerOrders.items.entitlement', 'items']);
    }

    public function with(): array
    {
        $user = auth()->user();
        $isBuyer = (int) $this->order->user_id === (int) $user->getKey();
        $isAdmin = $user->isPlatformSeller();

        return [
            'isBuyer' => $isBuyer,
            // Sellers only see their own part of somebody else's order.
            'sellerOrders' => $this->order->sellerOrders->filter(
                fn ($sellerOrder) => $isBuyer || $isAdmin || (int) $sellerOrder->seller_id === (int) $user->getKey()
            ),
            'showTotals' => $isBuyer || $isAdmin,
        ];
    }
}; ?>

<x-layouts.app>
    @volt('dashboard.orders.show')
    <div class="space-y-6">
        <a href="{{ route('dashboard.orders') }}" class="inline-flex items-center gap-1 text-sm font-medium text-gray-600 hover:text-gray-900">
            <x-phosphor-arrow-left class="h-4 w-4" /> Back to my orders
        </a>

        <x-filament::section
            :heading="'Order '.$order->number"
            :description="'Placed '.($order->paid_at ?? $order->created_at)->format('F j, Y').($isBuyer ? '' : ' by a buyer')">
            <x-slot name="headerEnd">
                <x-marketplace.badge :color="$order->status->color()">{{ $order->status->label() }}</x-marketplace.badge>
            </x-slot>

            @if ($order->status === \App\Enums\OrderStatus::PENDING)
                <p class="text-sm text-gray-600">Waiting for the payment confirmation from Stripe.</p>
            @else
                <p class="text-sm text-gray-600">{{ $order->items->count() }} {{ \Illuminate\Support\Str::plural('item', $order->items->count()) }} from {{ $sellerOrders->count() }} {{ \Illuminate\Support\Str::plural('seller', $sellerOrders->count()) }}.</p>
            @endif
        </x-filament::section>

        <div class="gap-6 lg:grid lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="space-y-6">
                @forelse ($sellerOrders as $sellerOrder)
                    <x-marketplace.seller-order :sellerOrder="$sellerOrder" :order="$order" wire:key="so-{{ $sellerOrder->id }}" />
                @empty
                    <p class="text-gray-500">There is nothing to show for this order.</p>
                @endforelse
            </div>

            <aside class="mt-6 space-y-6 lg:mt-0">
                @if ($showTotals)
                    <x-filament::section heading="Payment">
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between text-gray-600"><dt>Items</dt><dd>{{ money($order->subtotal_cents, $order->currency) }}</dd></div>
                            <div class="flex justify-between text-gray-600"><dt>Shipping</dt><dd>{{ money($order->shipping_cents, $order->currency) }}</dd></div>
                            @if ($order->tax_cents > 0)
                                <div class="flex justify-between text-gray-600"><dt>Tax</dt><dd>{{ money($order->tax_cents, $order->currency) }}</dd></div>
                            @endif
                            <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-bold text-gray-950"><dt>Total</dt><dd>{{ money($order->total_cents, $order->currency) }}</dd></div>
                        </dl>
                        @if ($order->paid_at)
                            <p class="mt-3 text-xs text-gray-500">Paid {{ $order->paid_at->format('M j, Y H:i') }} via Stripe</p>
                        @endif
                    </x-filament::section>
                @endif

                @if (filled($order->shipping_address))
                    <x-filament::section heading="Ship to">
                        @php($address = $order->shipping_address)
                        <address class="text-sm not-italic text-gray-700">
                            {{ $address['name'] ?? '' }}<br>
                            {{ $address['line1'] ?? '' }}@if (filled($address['line2'] ?? null)), {{ $address['line2'] }}@endif<br>
                            {{ $address['postal_code'] ?? '' }} {{ $address['city'] ?? '' }}@if (filled($address['state'] ?? null)), {{ $address['state'] }}@endif<br>
                            {{ Countries::name($address['country'] ?? null) }}
                        </address>
                    </x-filament::section>
                @endif
            </aside>
        </div>
    </div>
    @endvolt
</x-layouts.app>
