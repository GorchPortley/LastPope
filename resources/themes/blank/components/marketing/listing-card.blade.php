{{--
    One marketplace listing as a card (docs/STYLE.md "Cards"), the counterpart of the design and driver cards: photo first,
    type pill, "Pair / Set" seal, name, "By" shop, a mono row of condition / origin / stock, then the price and a Specs
    button that slides the full specification panel over the card.
    Eager-load seller.sellerProfile, driver and design to keep the grid free of extra queries.
--}}
@props(['listing', 'listName' => 'Marketplace'])

@php
    $photo = $listing->photos[0] ?? null;
    $shop = $listing->seller?->sellerProfile?->shop_name ?: $listing->seller?->name;
    $url = route('listing', ['uuid' => $listing->uuid]);
    $select = \App\Support\Analytics\Ga4::selectItem($listName, $listing);
    $soldAs = $listing->isLot() ? ($listing->units_per_lot === 2 ? 'Pair' : 'Set of '.$listing->units_per_lot) : 'Single item';
    $domestic = $listing->shipping_domestic_cents > 0 ? money($listing->shipping_domestic_cents) : 'Free';
@endphp

<article x-data="{ showSpecs: false }" {{ $attributes->class(['group relative flex h-full flex-col overflow-hidden border border-rule bg-white transition-colors hover:border-ink']) }} data-listing-card="{{ $listing->uuid }}">
    <div class="relative aspect-[4/3] overflow-hidden bg-hair">
        @if ($photo)
            <x-marketing.card-image :path="$photo" :alt="$listing->title" fit="cover" class="transition-transform duration-300 group-hover:scale-[1.04]" />
        @else
            <div class="flex h-full w-full items-center justify-center"><x-phosphor-image-duotone class="h-16 w-16 text-ink-3" /></div>
        @endif
        <span class="sd-pill absolute left-2.5 top-2.5 max-w-[60%] truncate">{{ $listing->category->label() }}</span>
        @if ($listing->isLot())
            <span class="sd-seal absolute right-2.5 top-2.5">{{ $soldAs }}</span>
        @endif
    </div>

    <div class="flex flex-1 flex-col gap-2 px-3.5 pb-3.5 pt-3">
        <h2 class="font-condensed text-[21px] font-bold leading-[1.1] text-ink line-clamp-2">
            <a href="{{ $url }}" data-ga4-click="{{ $select }}" class="text-ink no-underline after:absolute after:inset-0 after:content-['']">{{ $listing->title }}</a>
        </h2>
        <p class="truncate text-sm text-ink-2">By: {{ $shop }}</p>
        <x-marketing.card-specs :rows="[
            ['Condition', $listing->condition->label()],
            ['Ships from', $listing->ships_from_country],
            ['In stock', (string) $listing->quantity],
        ]" />
        <div class="mt-auto flex items-center justify-between gap-2.5 border-t border-dotted border-rule pt-2.5">
            <span class="font-condensed text-xl font-bold leading-none text-ink">{{ money($listing->price_cents) }}@if ($listing->isLot()) <span class="font-sans text-xs font-normal text-ink-2">per {{ $listing->units_per_lot === 2 ? 'pair' : 'set' }}</span>@endif</span>
            <button type="button" @click="showSpecs = true" x-show="!showSpecs" class="sd-btn sd-btn-sm relative z-10 whitespace-nowrap">Specs</button>
        </div>
    </div>

    <!-- Specs panel, slides over the card -->
    <div
        x-show="showSpecs"
        x-cloak
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="translate-y-full"
        x-transition:enter-end="translate-y-0"
        x-transition:leave="transition ease-in duration-300"
        x-transition:leave-start="translate-y-0"
        x-transition:leave-end="translate-y-full"
        class="absolute inset-0 z-20 flex flex-col bg-white">

        <div class="flex-grow overflow-auto p-3.5">
            <h3 class="mb-3 font-condensed text-lg font-bold uppercase leading-tight text-ink line-clamp-2">{{ $listing->title }} Specifications</h3>

            <table class="w-full border-collapse text-sm">
                <tbody>
                    <tr class="border-b border-hair">
                        <td class="py-2 text-ink-2">Condition</td>
                        <td class="py-2 text-right text-sm font-medium tabular-nums text-ink">{{ $listing->condition->label() }}</td>
                    </tr>
                    <tr class="border-b border-hair">
                        <td class="py-2 text-ink-2">Sold as</td>
                        <td class="py-2 text-right text-sm font-medium tabular-nums text-ink">{{ $soldAs }}</td>
                    </tr>
                    <tr class="border-b border-hair">
                        <td class="py-2 text-ink-2">In stock</td>
                        <td class="py-2 text-right text-sm font-medium tabular-nums text-ink">{{ $listing->quantity }}</td>
                    </tr>
                    <tr class="border-b border-hair">
                        <td class="py-2 text-ink-2">Shipping ({{ $listing->ships_from_country }})</td>
                        <td class="py-2 text-right text-sm font-medium tabular-nums text-ink">{{ $domestic }}</td>
                    </tr>
                    <tr class="border-b border-hair">
                        <td class="py-2 text-ink-2">Handling time</td>
                        <td class="py-2 text-right text-sm font-medium tabular-nums text-ink">{{ $listing->handling_days }} {{ \Illuminate\Support\Str::plural('day', $listing->handling_days) }}</td>
                    </tr>
                    <tr class="border-b border-hair">
                        <td class="py-2 text-ink-2">Returns</td>
                        <td class="py-2 text-right text-sm font-medium tabular-nums text-ink">{{ $listing->returns_accepted ? 'Accepted' : 'No returns' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex gap-2 p-3.5 pt-0">
            <a href="{{ $url }}" data-ga4-click="{{ $select }}" class="sd-btn sd-btn-go sd-btn-sm flex-grow">View details</a>
            <button type="button" @click="showSpecs = false" class="sd-btn sd-btn-sm flex-grow">Close</button>
        </div>
    </div>
</article>
