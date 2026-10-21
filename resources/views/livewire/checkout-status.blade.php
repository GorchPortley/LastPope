<div
    @if ($polling) wire:poll.3s="poll" @endif
    data-order-status="{{ $order->status->value }}"
    class="mx-auto max-w-3xl py-6"
>
    @if ($order->status === \App\Enums\OrderStatus::CANCELLED)
        <div class="text-center">
            <x-phosphor-x-circle-duotone class="mx-auto h-14 w-14 text-red-500" />
            <h1 class="mt-3 text-3xl font-bold text-gray-900">This order was cancelled</h1>
            <p class="mt-2 text-gray-600">No payment was taken for order {{ $order->number }}. Your cart is unchanged.</p>
            <x-button tag="a" href="{{ route('cart') }}" class="mt-6">Back to your cart</x-button>
        </div>
    @elseif ($pending)
        <div class="text-center">
            @if ($polling)
                <svg class="mx-auto h-12 w-12 animate-spin text-brand-800" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            @else
                <x-phosphor-hourglass-duotone class="mx-auto h-14 w-14 text-amber-500" />
            @endif
            <h1 class="mt-3 text-3xl font-bold text-gray-900">Confirming your payment...</h1>
            <p class="mt-2 text-gray-600">
                Order <strong>{{ $order->number }}</strong> is waiting for the payment confirmation from Stripe.
                @if ($polling)
                    This page updates by itself.
                @else
                    This is taking longer than usual. You will get an e-mail when it is confirmed; you can also check
                    <a class="text-brand-700 underline" href="{{ route('dashboard.orders') }}">your orders</a>.
                @endif
            </p>
        </div>
    @else
        <div class="text-center">
            <x-phosphor-check-circle-duotone class="mx-auto h-14 w-14 text-green-600" />
            <h1 class="mt-3 text-3xl font-bold text-gray-900">Thank you for your order!</h1>
            <p class="mt-2 text-gray-600">
                Order <strong>{{ $order->number }}</strong> is {{ strtolower($order->status->label()) }}. A receipt was sent to your e-mail.
            </p>
        </div>

        <div class="mt-8 divide-y divide-gray-200 overflow-hidden rounded-lg border-2 border-gray-100 bg-white shadow-sm">
            <div class="flex items-center justify-between bg-gray-50 px-5 py-3 text-sm">
                <span class="font-semibold text-gray-900">Receipt</span>
                <span class="text-gray-500">{{ ($order->paid_at ?? $order->created_at)->format('M j, Y') }}</span>
            </div>
            @foreach ($order->items as $item)
                <div class="flex justify-between gap-4 px-5 py-3 text-gray-900">
                    <span class="min-w-0">{{ $item->quantity > 1 ? $item->quantity.' x ' : '' }}{{ $item->title }}</span>
                    <span class="flex-shrink-0">{{ money($item->line_total_cents, $order->currency) }}</span>
                </div>
            @endforeach
            @if ($order->shipping_cents > 0)
                <div class="flex justify-between px-5 py-3 text-gray-700">
                    <span>Shipping</span>
                    <span>{{ money($order->shipping_cents, $order->currency) }}</span>
                </div>
            @endif
            @if ($order->tax_cents > 0)
                <div class="flex justify-between px-5 py-3 text-gray-700">
                    <span>Tax</span>
                    <span>{{ money($order->tax_cents, $order->currency) }}</span>
                </div>
            @endif
            <div class="flex justify-between px-5 py-3 text-lg font-bold text-gray-900">
                <span>Total</span>
                <span>{{ money($order->total_cents, $order->currency) }}</span>
            </div>
        </div>

        <div class="mt-6 space-y-2 text-sm text-gray-600">
            @if ($hasDigital)
                <p>Your digital downloads are in <a class="font-medium text-brand-700 underline" href="{{ route('dashboard.library') }}">your library</a>.</p>
            @endif
            @if ($hasPhysical)
                <p>The sellers will ship your items; you will get an e-mail with the tracking details. Follow them in <a class="font-medium text-brand-700 underline" href="{{ route('dashboard.orders') }}">your orders</a>.</p>
            @endif
        </div>

        <div class="mt-8 flex flex-wrap gap-3">
            @if ($hasDigital)
                <x-button tag="a" href="{{ route('dashboard.library') }}">Open my library</x-button>
            @endif
            <x-button tag="a" href="{{ route('dashboard.orders.show', ['order' => $order->number]) }}" :color="$hasDigital ? 'gray' : 'primary'">View order</x-button>
            <x-button tag="a" href="{{ route('marketplace') }}" color="gray">Keep shopping</x-button>
        </div>
    @endif
</div>
