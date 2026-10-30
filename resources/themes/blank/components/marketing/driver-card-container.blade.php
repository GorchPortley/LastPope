{{--
    A grid of driver cards (docs/STYLE.md "Cards"): square white photo (contain, padded), type pill and Official seal,
    then model, "By" brand, a mono row of size / impedance / power and a foot with the SDLabs stock line or the
    manufacturer price. Eager-load withCount('designs') and activeStoreListing.supplier.
--}}
@props([
    'drivers',
    'cols' => 'grid-cols-[repeat(auto-fill,minmax(250px,1fr))]',
    'padded' => true,
    'listName' => 'Drivers',
])
<x-analytics.events :events="count($drivers) ? [\App\Support\Analytics\Ga4::itemList($listName, $drivers)] : []" />
<div {{ $attributes->class(['grid gap-[18px]', $cols, 'p-4' => $padded]) }}>
    @foreach($drivers as $driver)
        @php($select = \App\Support\Analytics\Ga4::selectItem($listName, $driver))
        @php($cover = is_array($driver->card_image) ? ($driver->card_image[0] ?? null) : $driver->card_image)
        @php($designCount = $driver->designs_count ?? $driver->designs()->count())
        {{-- Stocked by the SDLabs store (the active store listing is eager-loaded by the page). --}}
        @php($stocked = $driver->storeListing()?->inStock() ? $driver->storeListing() : null)
        <article wire:key="{{ $driver->id }}" class="group relative flex flex-col border border-rule bg-white transition-colors hover:border-ink" data-driver-card="{{ $driver->id }}">
            <div class="relative aspect-square overflow-hidden border-b border-hair bg-white">
                <x-marketing.card-image :path="$cover" :alt="$driver->model" class="h-full w-full object-contain p-3" />
                @if($driver->category)
                    <span class="sd-pill absolute left-2.5 top-2.5 max-w-[60%] truncate">{{ $driver->category }}</span>
                @endif
                @if($driver->official)
                    <span class="sd-seal absolute right-2.5 top-2.5">Official</span>
                @endif
            </div>

            <div class="flex flex-1 flex-col gap-2 px-3.5 pb-3.5 pt-3">
                <h2 class="font-condensed text-[21px] font-bold leading-[1.1] text-ink line-clamp-2">
                    <a href="/drivers/driver/{{ $driver->id }}" data-ga4-click="{{ $select }}" class="text-ink no-underline after:absolute after:inset-0 after:content-['']">{{ $driver->model }}</a>
                </h2>
                <p class="truncate text-sm text-ink-2">By: {{ $driver->brand }}</p>
                <x-marketing.card-specs :rows="[
                    ['Size', $driver->size ? $driver->size.'″' : null],
                    ['Impedance', $driver->impedance ? $driver->impedance.' Ω' : null],
                    ['Power', $driver->power ? $driver->power.' W' : null],
                    ['Used in', $designCount.' '.\Illuminate\Support\Str::plural('design', $designCount)],
                ]" />
                <div class="mt-auto flex items-center justify-between gap-2.5 border-t border-dotted border-rule pt-2.5">
                    @if($stocked)
                        <span class="font-condensed text-base font-bold leading-tight text-green-700" data-driver-in-stock>In stock at SDLabs &middot; {{ money($stocked->price_cents) }}</span>
                    @elseif($driver->msrp_cents)
                        <span class="text-sm text-ink-2">MSRP {{ money((int) $driver->msrp_cents) }}</span>
                    @else
                        <span class="text-sm text-ink-3">{{ $driver->tag }}</span>
                    @endif
                </div>
            </div>
        </article>
    @endforeach
</div>
