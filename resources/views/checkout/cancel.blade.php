{{-- Shown when the buyer leaves the Stripe payment page. Nothing was charged. P3 restyles this page. --}}
<x-layouts.marketing>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <h1 class="text-2xl font-bold mb-2 dark:text-white">Payment cancelled</h1>
        <p class="text-gray-600 dark:text-zinc-400">
            You were not charged{{ $orderNumber ? ' for order '.e($orderNumber) : '' }}. Your cart is exactly as you left it.
        </p>
        <a href="{{ route('cart') }}" class="mt-6 inline-block text-purple-600 hover:text-purple-800 dark:text-purple-400">Back to your cart</a>
    </div>
</x-layouts.marketing>
