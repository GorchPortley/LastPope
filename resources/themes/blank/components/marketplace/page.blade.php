{{--
    The skeleton of the owner's detail pages (see pages/drivers/driver/[id]): a grey page with a white,
    max-w-7xl panel, then the breadcrumb and its rule. Everything public that is not a card grid with a
    filter sidebar (cart, checkout, listing, shop...) sits in this.
--}}
@props(['crumbs' => []])

<div class="bg-zinc-200 min-h-[70vh] flex flex-col">
    <main {{ $attributes->class(['mx-auto w-full flex-1 bg-white max-w-7xl px-4 sm:px-6 lg:px-8 py-4']) }}>
        @if ($crumbs !== [])
            <x-marketplace.breadcrumb :items="$crumbs" />
        @endif

        {{ $slot }}
    </main>
</div>
