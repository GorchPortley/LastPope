{{-- Shown when the buyer leaves the Stripe payment page. Nothing was charged. --}}
<x-layouts.marketing :seo="['title' => 'Payment cancelled - '.config('app.name')]">
    <div class="mx-auto max-w-3xl px-4 py-12 text-center sm:px-6 lg:px-8">
        <x-phosphor-arrow-u-up-left-duotone class="mx-auto h-14 w-14 text-gray-400" />
        <h1 class="mt-3 text-3xl font-bold text-gray-900 dark:text-white">Payment cancelled</h1>
        <p class="mt-2 text-gray-600 dark:text-zinc-400">
            You were not charged{{ $orderNumber ? ' for order '.e($orderNumber) : '' }}. Your cart is exactly as you left it.
        </p>
        <div class="mt-6 flex flex-wrap justify-center gap-3">
            <x-button tag="a" href="{{ route('cart') }}">Back to your cart</x-button>
            <x-button tag="a" href="{{ route('marketplace') }}" color="gray">Keep browsing</x-button>
        </div>
    </div>
</x-layouts.marketing>
