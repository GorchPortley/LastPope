{{--
    Image carousel with the look of the one on the design and driver pages (square, rounded, round arrow
    buttons, dots), driven by Alpine so it needs no extra script. `images` is a list of URLs, or (to show resized
    copies) a list of stored public-disk paths with `paths` set: those become 960 px WebP thumbnails with a 1600 px
    one for high-density screens (App\Support\Thumbnails).
--}}
@props(['images' => [], 'alt' => '', 'paths' => false])

@php
    $images = collect($images)->filter()->values()->map(fn ($image) => $paths
        ? ['src' => \App\Support\Thumbnails::url($image, 960), 'srcset' => \App\Support\Thumbnails::srcset($image, [960, 1600])]
        : ['src' => $image, 'srcset' => ''])->all();
@endphp

<div
    x-data="{ index: 0, count: {{ count($images) }}, next() { this.index = (this.index + 1) % this.count }, prev() { this.index = (this.index - 1 + this.count) % this.count } }"
    @keydown.left.prevent="prev()" @keydown.right.prevent="next()"
    {{ $attributes->class(['relative w-full']) }}
>
    <div class="relative overflow-hidden rounded-lg aspect-square w-full">
        @forelse ($images as $image)
            <div class="w-full h-full" @if (! $loop->first) x-cloak @endif x-show="index === {{ $loop->index }}">
                <img
                    src="{{ $image['src'] }}"
                    @if ($image['srcset'] !== '') srcset="{{ $image['srcset'] }}" sizes="(min-width: 1024px) 600px, 100vw" @endif
                    decoding="async"
                    alt="{{ $alt }} - image {{ $loop->iteration }}"
                    class="absolute block w-full h-full object-contain"
                    @if (! $loop->first) loading="lazy" @endif
                >
            </div>
        @empty
            <div class="flex h-full w-full flex-col items-center justify-center bg-gray-50 text-gray-400">
                <x-phosphor-image-duotone class="h-16 w-16" />
                <span class="mt-2 text-sm">No photo</span>
            </div>
        @endforelse

        @if (count($images) > 1)
            <div class="absolute z-30 flex justify-center w-full space-x-3 bottom-5">
                @foreach ($images as $image)
                    <button type="button" @click="index = {{ $loop->index }}" aria-label="Slide {{ $loop->iteration }}"
                            :aria-current="index === {{ $loop->index }}"
                            :class="index === {{ $loop->index }} ? 'bg-white' : 'bg-white/50'"
                            class="w-3 h-3 invert rounded-full"></button>
                @endforeach
            </div>

            <button type="button" @click="prev()" class="invert absolute top-0 start-0 z-30 flex items-center justify-center h-full px-4 cursor-pointer group focus:outline-none">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-white/30 group-hover:bg-white/50 group-focus:ring-4 group-focus:ring-white group-focus:outline-none">
                    <svg class="w-4 h-4 text-white rtl:rotate-180" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 1 1 5l4 4"/>
                    </svg>
                    <span class="sr-only">Previous</span>
                </span>
            </button>
            <button type="button" @click="next()" class="invert absolute top-0 end-0 z-30 flex items-center justify-center h-full px-4 cursor-pointer group focus:outline-none">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-white/30 group-hover:bg-white/50 group-focus:ring-4 group-focus:ring-white group-focus:outline-none">
                    <svg class="w-4 h-4 text-white rtl:rotate-180" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4"/>
                    </svg>
                    <span class="sr-only">Next</span>
                </span>
            </button>
        @endif
    </div>
</div>
