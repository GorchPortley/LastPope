{{--
    The compact home hero (about 300 px on desktop): eyebrow, short headline with one purple word, a one-line lede,
    two buttons and, from the lg width up, a small photo of a featured design. `featured` is an optional App\Models\Design.
--}}
@props(['featured' => null])

@php
    $cover = $featured ? (is_array($featured->card_image) ? ($featured->card_image[0] ?? null) : $featured->card_image) : null;
@endphp

<section class="border-b border-rule bg-white">
    <div class="mx-auto grid max-w-7xl items-center gap-8 px-4 py-8 sm:px-6 lg:grid-cols-[minmax(0,1fr)_320px] lg:px-8">
        <div>
            <p class="sd-label">Discover Your Next Favorite Speaker</p>
            <h1 class="sd-title mt-3 text-[clamp(36px,5.2vw,58px)] leading-[0.95]">Speaker designs you can <span class="text-brand-800">build</span></h1>
            <p class="mt-3 max-w-[52ch] text-lg text-ink-2">Plans, measurements and drivers from the community, plus kits and used gear in the marketplace.</p>
            <div class="mt-5 flex flex-wrap gap-2.5">
                <a href="{{ route('designs') }}" class="sd-btn sd-btn-go">Browse designs</a>
                <a href="{{ route('drivers') }}" class="sd-btn">Find a driver</a>
            </div>
        </div>

        @if ($featured)
            <a href="/designs/design/{{ $featured->id }}" class="group relative hidden border border-rule bg-hair no-underline transition-colors hover:border-ink lg:block" aria-label="Featured design: {{ $featured->name }}">
                <div class="aspect-[4/3] overflow-hidden">
                    <x-marketing.card-image :path="$cover" :alt="$featured->name" fit="cover" class="transition-transform duration-300 group-hover:scale-[1.04]" />
                </div>
                <div class="flex items-center justify-between gap-3 border-t border-rule bg-white px-3 py-2">
                    <span class="truncate font-condensed text-lg font-bold leading-tight text-ink">{{ $featured->name }}</span>
                    <span class="sd-pill shrink-0">Newest</span>
                </div>
            </a>
        @endif
    </div>
</section>
