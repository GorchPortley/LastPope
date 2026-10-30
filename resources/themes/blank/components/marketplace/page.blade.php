{{--
    The skeleton of the owner's detail pages (see pages/drivers/driver/[id]): a paper page with a centred column, then
    the breadcrumb and its rule. Everything public that is not a card grid with a filter sidebar (cart, checkout,
    listing, shop...) sits in this; put sheets (x-marketplace.box, .sd-sheet) inside.
--}}
@props(['crumbs' => []])

<div class="flex min-h-[70vh] flex-col bg-paper">
    <main {{ $attributes->class(['mx-auto w-full max-w-7xl flex-1 px-4 py-4 pb-12 sm:px-6 lg:px-8']) }}>
        @if ($crumbs !== [])
            <x-marketplace.breadcrumb :items="$crumbs" />
        @endif

        {{ $slot }}
    </main>
</div>
