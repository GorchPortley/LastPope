{{-- Shown when the buyer leaves the Stripe payment page. Nothing was charged. --}}
<x-layouts.marketing :seo="['title' => 'Payment cancelled - '.config('app.name')]">
    <x-marketplace.page :crumbs="[['Marketplace', route('marketplace')], ['Cart', route('cart')], ['Payment cancelled', null]]">
        <x-marketplace.empty-state
            heading="Payment cancelled"
            :description="'You were not charged'.($orderNumber ? ' for order '.e($orderNumber) : '').'. Your cart is exactly as you left it.'"
            icon="phosphor-arrow-u-up-left"
            class="mx-auto max-w-3xl my-8">
            <x-button tag="a" href="{{ route('cart') }}">Back to your cart</x-button>
            <x-button tag="a" href="{{ route('marketplace') }}" color="gray">Keep browsing</x-button>
        </x-marketplace.empty-state>
    </x-marketplace.page>
</x-layouts.marketing>
