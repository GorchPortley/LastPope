{{--
    "Thank you" page after Stripe. It only displays the order (see CheckoutStatus): the Stripe webhook does the
    fulfilment, so a just-paid order may still be pending for a moment and the status updates by polling.
--}}
<x-layouts.marketing :seo="['title' => 'Order '.$order->number.' - '.config('app.name')]">
    <x-marketplace.page :crumbs="[['Marketplace', route('marketplace')], ['Cart', route('cart')], ['Order '.$order->number, null]]">
        <livewire:checkout-status :order="$order" />
    </x-marketplace.page>
</x-layouts.marketing>
