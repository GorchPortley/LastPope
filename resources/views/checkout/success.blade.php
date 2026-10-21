{{--
    Plain "thank you" page. It only displays the order: the Stripe webhook does the fulfilment, so a
    just-paid order may still be pending for a moment (this page then reloads itself a few times).
    P3 restyles this page.
--}}
@php
    $pending = $order->status === \App\Enums\OrderStatus::PENDING;
    $refreshing = $pending && $attempt < 10;
    $hasDigital = $order->items->contains(fn ($item) => $item->is_digital);
    $hasPhysical = $order->items->contains(fn ($item) => ! $item->is_digital);
@endphp

<x-layouts.marketing>
    @if($refreshing)
        @push('head')
            <meta http-equiv="refresh" content="3;url={{ route('checkout.success', ['session_id' => $sessionId, 'attempt' => $attempt + 1]) }}">
        @endpush
    @endif

    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8" data-order-status="{{ $order->status->value }}">
        @if($order->status === \App\Enums\OrderStatus::CANCELLED)
            <h1 class="text-2xl font-bold mb-2 dark:text-white">This order was cancelled</h1>
            <p class="text-gray-600 dark:text-zinc-400">No payment was taken for order {{ $order->number }}. Your cart is unchanged.</p>
            <a href="{{ route('cart') }}" class="mt-6 inline-block text-purple-600 hover:text-purple-800 dark:text-purple-400">Back to your cart</a>
        @elseif($pending)
            <h1 class="text-2xl font-bold mb-2 dark:text-white">Confirming your payment...</h1>
            <p class="text-gray-600 dark:text-zinc-400">
                Order {{ $order->number }} is waiting for the payment confirmation from Stripe.
                @if($refreshing)
                    This page updates by itself.
                @else
                    This is taking longer than usual. You will get an e-mail when it is confirmed; you can also check
                    <a class="text-purple-600 hover:text-purple-800 dark:text-purple-400" href="{{ url('/dashboard/orders') }}">your orders</a>.
                @endif
            </p>
        @else
            <h1 class="text-2xl font-bold mb-2 dark:text-white">Thank you for your order!</h1>
            <p class="text-gray-600 dark:text-zinc-400">
                Order <strong>{{ $order->number }}</strong> is {{ strtolower($order->status->label()) }}. A receipt was sent to your e-mail.
            </p>

            <div class="mt-6 bg-white dark:bg-zinc-800 rounded-lg shadow divide-y divide-gray-200 dark:divide-zinc-700">
                @foreach($order->items as $item)
                    <div class="p-4 flex justify-between dark:text-white">
                        <span>{{ $item->quantity > 1 ? $item->quantity.' x ' : '' }}{{ $item->title }}</span>
                        <span>{{ money($item->line_total_cents, $order->currency) }}</span>
                    </div>
                @endforeach
                @if($order->shipping_cents > 0)
                    <div class="p-4 flex justify-between dark:text-white">
                        <span>Shipping</span>
                        <span>{{ money($order->shipping_cents, $order->currency) }}</span>
                    </div>
                @endif
                @if($order->tax_cents > 0)
                    <div class="p-4 flex justify-between dark:text-white">
                        <span>Tax</span>
                        <span>{{ money($order->tax_cents, $order->currency) }}</span>
                    </div>
                @endif
                <div class="p-4 flex justify-between font-semibold dark:text-white">
                    <span>Total</span>
                    <span>{{ money($order->total_cents, $order->currency) }}</span>
                </div>
            </div>

            <div class="mt-6 space-y-2 text-sm text-gray-600 dark:text-zinc-400">
                @if($hasDigital)
                    <p>Your digital downloads are in <a class="text-purple-600 hover:text-purple-800 dark:text-purple-400" href="{{ url('/dashboard/library') }}">your library</a>.</p>
                @endif
                @if($hasPhysical)
                    <p>The sellers will ship your items; you will get an e-mail with the tracking details. Follow them in <a class="text-purple-600 hover:text-purple-800 dark:text-purple-400" href="{{ url('/dashboard/orders') }}">your orders</a>.</p>
                @endif
            </div>
        @endif
    </div>
</x-layouts.marketing>
