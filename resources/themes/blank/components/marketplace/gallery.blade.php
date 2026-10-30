{{--
    Photo gallery of the detail pages (design, driver, listing): one main picture in a square-cornered frame, previous /
    next buttons and a row of thumbnails under it, both only when there is more than one picture. Driven by Alpine.

    `images` is a list of URLs, or (to show resized copies) a list of stored public-disk paths with `paths` set: those
    become 960 px WebP pictures with a 1600 px one for high-density screens, and 160 px thumbnails (App\Support\Thumbnails).
    `ratio` is 'square' (default, for drivers and listings) or '4:3' (designs); `fit` is 'contain' (default, on white)
    or 'cover'.

    Clicking the picture (or the expand button) opens the full, uncropped original in a full-screen viewer with the same
    previous / next controls; Escape, the close button or a click on the backdrop closes it.
--}}
@props(['images' => [], 'alt' => '', 'paths' => false, 'ratio' => 'square', 'fit' => 'contain'])

@php
    $images = collect($images)->filter()->values()->map(fn ($image) => $paths
        ? [
            'src' => \App\Support\Thumbnails::url($image, 960),
            'srcset' => \App\Support\Thumbnails::srcset($image, [960, 1600]),
            'thumb' => \App\Support\Thumbnails::url($image, 160),
            'full' => \App\Support\Thumbnails::original($image),
        ]
        : ['src' => $image, 'srcset' => '', 'thumb' => $image, 'full' => $image])->all();
@endphp

<div
    x-data="{ index: 0, count: {{ count($images) }}, full: false, fullUrls: @js(array_column($images, 'full')),
        next() { this.index = (this.index + 1) % this.count }, prev() { this.index = (this.index - 1 + this.count) % this.count },
        open() { this.full = true; document.documentElement.style.overflow = 'hidden'; this.$nextTick(() => this.$refs.close?.focus()) },
        close() { this.full = false; document.documentElement.style.overflow = ''; } }"
    @keydown.left.prevent="prev()" @keydown.right.prevent="next()"
    @keydown.escape.window="full && close()"
    {{ $attributes->class(['relative w-full']) }}
    data-gallery
>
    <div @class([
        'relative w-full overflow-hidden border border-rule',
        'aspect-square bg-white' => $ratio !== '4:3',
        'aspect-[4/3] bg-hair' => $ratio === '4:3',
    ])>
        @forelse ($images as $image)
            <div class="h-full w-full" @if (! $loop->first) x-cloak @endif x-show="index === {{ $loop->index }}">
                <img
                    @click="open()" title="Click to view the full picture" style="cursor: zoom-in"
                    src="{{ $image['src'] }}"
                    @if ($image['srcset'] !== '') srcset="{{ $image['srcset'] }}" sizes="(min-width: 1024px) 700px, 100vw" @endif
                    decoding="async"
                    alt="{{ $alt }} - image {{ $loop->iteration }}"
                    @class(['absolute block h-full w-full', 'object-cover' => $fit === 'cover', 'object-contain' => $fit !== 'cover'])
                    @if (! $loop->first) loading="lazy" @endif
                >
            </div>
        @empty
            <div class="flex h-full w-full flex-col items-center justify-center bg-paper text-ink-3">
                <x-phosphor-image-duotone class="h-16 w-16" />
                <span class="mt-2 text-sm">No photo</span>
            </div>
        @endforelse

        @if (count($images) > 0)
            <button type="button" @click="open()" aria-label="View the full picture" class="absolute end-0 top-0 z-10 flex h-10 w-10 items-center justify-center border-b border-s border-ink bg-white text-ink hover:bg-ink hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent-500" data-gallery-expand>
                <x-phosphor-arrows-out class="h-5 w-5 !w-5" aria-hidden="true" />
            </button>
        @endif

        @if (count($images) > 1)
            <button type="button" @click="prev()" aria-label="Previous photo" class="absolute start-0 top-1/2 z-10 flex h-11 w-11 -translate-y-1/2 items-center justify-center border border-ink bg-white text-ink hover:bg-ink hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent-500">
                <svg class="h-4 w-4 !w-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 1 1 5l4 4"/></svg>
            </button>
            <button type="button" @click="next()" aria-label="Next photo" class="absolute end-0 top-1/2 z-10 flex h-11 w-11 -translate-y-1/2 items-center justify-center border border-ink bg-white text-ink hover:bg-ink hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent-500">
                <svg class="h-4 w-4 !w-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4"/></svg>
            </button>
            <p class="sd-num absolute bottom-0 end-0 z-10 bg-ink px-2 py-1 text-[11px] text-white" aria-live="polite"><span x-text="index + 1"></span> / {{ count($images) }}</p>
        @endif
    </div>

    @if (count($images) > 1)
        <div class="mt-2 grid grid-cols-5 gap-2 sm:grid-cols-6" data-gallery-thumbs>
            @foreach ($images as $image)
                <button type="button" @click="index = {{ $loop->index }}" aria-label="Show photo {{ $loop->iteration }}" :aria-current="index === {{ $loop->index }}"
                        class="aspect-square overflow-hidden border bg-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent-500"
                        :class="index === {{ $loop->index }} ? 'border-ink' : 'border-rule opacity-70 hover:opacity-100'">
                    <img src="{{ $image['thumb'] }}" alt="" loading="lazy" decoding="async" class="h-full w-full object-cover">
                </button>
            @endforeach
        </div>
    @endif

    @if (count($images) > 0)
        {{-- full-screen viewer: the original file, never cropped --}}
        <template x-teleport="body">
            <div x-show="full" x-cloak x-transition.opacity @click.self="close()" role="dialog" aria-modal="true" aria-label="{{ $alt }} - full picture"
                 class="fixed inset-0 z-[100] flex items-center justify-center bg-ink/95 p-[max(1rem,env(safe-area-inset-top,0px))_max(1rem,env(safe-area-inset-right,0px))_max(1rem,env(safe-area-inset-bottom,0px))_max(1rem,env(safe-area-inset-left,0px))]" data-gallery-lightbox>
                {{-- only rendered while open, so a closed viewer is not an empty (broken) image --}}
                <template x-if="full">
                    <img :src="fullUrls[index]" alt="{{ $alt }}" class="max-h-full max-w-full object-contain" @click.self="close()">
                </template>
                <button type="button" x-ref="close" @click="close()" aria-label="Close" class="absolute end-[max(0.75rem,env(safe-area-inset-right,0px))] top-[max(0.75rem,env(safe-area-inset-top,0px))] flex h-11 w-11 items-center justify-center border border-white/70 text-white hover:bg-white hover:text-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent-500">
                    <x-phosphor-x class="h-5 w-5 !w-5" aria-hidden="true" />
                </button>
                @if (count($images) > 1)
                    <button type="button" @click="prev()" aria-label="Previous photo" class="absolute start-[max(0.75rem,env(safe-area-inset-left,0px))] top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center border border-white/70 text-white hover:bg-white hover:text-ink">
                        <x-phosphor-caret-left class="h-5 w-5 !w-5" aria-hidden="true" />
                    </button>
                    <button type="button" @click="next()" aria-label="Next photo" class="absolute end-[max(0.75rem,env(safe-area-inset-right,0px))] top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center border border-white/70 text-white hover:bg-white hover:text-ink">
                        <x-phosphor-caret-right class="h-5 w-5 !w-5" aria-hidden="true" />
                    </button>
                    <p class="sd-num absolute bottom-[max(0.75rem,env(safe-area-inset-bottom,0px))] left-1/2 -translate-x-1/2 bg-white/10 px-2 py-1 text-xs text-white"><span x-text="index + 1"></span> / {{ count($images) }}</p>
                @endif
            </div>
        </template>
    @endif
</div>
