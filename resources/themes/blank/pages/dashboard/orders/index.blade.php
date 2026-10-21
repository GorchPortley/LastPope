<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use function Laravel\Folio\{middleware, name};

middleware('auth');
name('dashboard.orders');

new class extends Component {
    use WithPagination;

    public function with(): array
    {
        return [
            'orders' => Order::query()
                ->where('user_id', auth()->id())
                ->where('status', '!=', OrderStatus::CANCELLED->value)
                ->with(['sellerOrders.seller.sellerProfile', 'sellerOrders.items.entitlement'])
                ->latest()
                ->paginate(8),
        ];
    }
}; ?>

<x-layouts.app>
    @volt('dashboard.orders')
    <x-app.container>
        <x-app.heading
            title="My orders"
            description="Track what you bought, confirm parcels when they arrive, and open receipts."
            :border="true" />

        @if ($orders->isEmpty())
            <div class="mt-8 border border-dashed border-gray-300 bg-white p-12 text-center">
                <x-phosphor-receipt-duotone class="mx-auto h-14 w-14 text-gray-400" />
                <p class="mt-3 text-lg font-medium text-gray-900">No orders yet</p>
                <p class="mt-1 text-sm text-gray-600">When you buy something, it shows up here.</p>
                <x-button tag="a" href="{{ route('marketplace') }}" class="mt-5">Browse the marketplace</x-button>
            </div>
        @else
            <div class="mt-6 space-y-8">
                @foreach ($orders as $order)
                    <section wire:key="order-{{ $order->id }}" aria-label="Order {{ $order->number }}">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                            <div class="flex flex-wrap items-center gap-3">
                                <h2 class="text-lg font-semibold text-gray-900">
                                    <a href="{{ route('dashboard.orders.show', ['order' => $order->number]) }}" class="hover:underline">Order {{ $order->number }}</a>
                                </h2>
                                <x-marketplace.badge :color="$order->status->color()">{{ $order->status->label() }}</x-marketplace.badge>
                            </div>
                            <p class="text-sm text-gray-500">
                                {{ ($order->paid_at ?? $order->created_at)->format('M j, Y') }}
                                &middot; <span class="font-semibold text-gray-900">{{ money($order->total_cents, $order->currency) }}</span>
                            </p>
                        </div>

                        <div class="space-y-3">
                            @foreach ($order->sellerOrders as $sellerOrder)
                                <x-marketplace.seller-order :sellerOrder="$sellerOrder" :order="$order" wire:key="so-{{ $sellerOrder->id }}" />
                            @endforeach
                        </div>

                        <div class="mt-2 text-right">
                            <a href="{{ route('dashboard.orders.show', ['order' => $order->number]) }}" class="text-sm font-medium text-purple-700 underline hover:text-purple-900">Order details and receipt</a>
                        </div>
                    </section>
                @endforeach
            </div>

            <div class="mt-8">{{ $orders->links() }}</div>
        @endif
    </x-app.container>
    @endvolt
</x-layouts.app>
