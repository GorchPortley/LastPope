{{--
    A grid of design cards (docs/STYLE.md "Cards"): photo first (4:3), type pill and Official seal on it, then name,
    byline, one row of mono specs, a dotted rule, price and rating. The whole card is a link (stretched on the name).
    Eager-load designer.roles, designer.sellerProfile and withAvg('reviews','rating')/withCount('reviews') (and
    withCount('components') for the driver count) so the grid costs no extra queries.
--}}
@props([
    'designs',
    'cols' => 'grid-cols-[repeat(auto-fill,minmax(250px,1fr))]',
    'padded' => true,
    'listName' => 'Designs',
])
<div>
<x-analytics.events :events="count($designs) ? [\App\Support\Analytics\Ga4::itemList($listName, $designs)] : []" />
<div {{ $attributes->class(['grid gap-[18px]', $cols, 'p-4' => $padded]) }}>
    @foreach($designs as $design)
        @php($select = \App\Support\Analytics\Ga4::selectItem($listName, $design))
        @php($rating = $design->ratingSummary())
        @php($cover = is_array($design->card_image) ? ($design->card_image[0] ?? null) : $design->card_image)
        @php($driverCount = array_key_exists('components_count', $design->getAttributes()) ? (int) $design->components_count : null)
        <article wire:key="{{ $design->id }}" class="group relative flex flex-col border border-rule bg-white transition-colors hover:border-ink" data-design-card="{{ $design->id }}">
            <div class="relative aspect-[4/3] overflow-hidden bg-hair">
                <x-marketing.card-image :path="$cover" :alt="$design->name" fit="cover" class="transition-transform duration-300 group-hover:scale-[1.04]" />
                @if($design->category)
                    <span class="sd-pill absolute left-2.5 top-2.5 max-w-[60%] truncate">{{ $design->category }}</span>
                @endif
                @if($design->official)
                    <span class="sd-seal absolute right-2.5 top-2.5">Official</span>
                @endif
            </div>

            <div class="flex flex-1 flex-col gap-2 px-3.5 pb-3.5 pt-3">
                <h2 class="font-condensed text-[21px] font-bold leading-[1.1] text-ink line-clamp-2">
                    <a href="/designs/design/{{ $design->id }}" data-ga4-click="{{ $select }}" class="text-ink no-underline after:absolute after:inset-0 after:content-['']">{{ $design->name }}</a>
                </h2>
                <p class="truncate text-sm text-ink-2">By: {{ $design->designer?->name }}</p>
                <p class="flex flex-wrap gap-x-3 gap-y-1 font-mono text-xs leading-snug text-ink-2">
                    @if($design->impedance)<span>{{ $design->impedance }} &Omega;</span>@endif
                    @if($design->power)<span>{{ $design->power }} W</span>@endif
                    @if($driverCount)<span>{{ $driverCount }} {{ \Illuminate\Support\Str::plural('driver', $driverCount) }}</span>@endif
                    @if($design->build_cost_cents)<span>Build {{ money((int) $design->build_cost_cents) }}</span>@endif
                </p>
                <div class="mt-auto flex items-center justify-between gap-2.5 border-t border-dotted border-rule pt-2.5">
                    @if($design->price_cents > 0)
                        <span class="font-condensed text-xl font-bold leading-none text-ink">{{ money($design->price_cents) }}</span>
                    @else
                        <span class="font-condensed text-xl font-bold leading-none text-green-700">Free</span>
                    @endif
                    <x-marketing.stars :average="$rating['average']" :count="$rating['count']" />
                </div>
            </div>
        </article>
    @endforeach
</div>
</div>
