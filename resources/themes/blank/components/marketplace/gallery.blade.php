{{--
    Image gallery with previous/next buttons, indicators and thumbnails, same look as the carousel on the design page
    but driven by Alpine (no extra script to load). `images` is a list of URLs.
--}}
@props(['images' => [], 'alt' => ''])

@php($images = array_values(array_filter($images)))

<div
    x-data="{ index: 0, count: {{ count($images) }}, next() { this.index = (this.index + 1) % this.count }, prev() { this.index = (this.index - 1 + this.count) % this.count } }"
    @keydown.left.prevent="prev()" @keydown.right.prevent="next()"
    {{ $attributes->class(['w-full']) }}
>
    <div class="relative aspect-square w-full overflow-hidden border border-gray-200 bg-gray-50 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($images as $url)
            <img
                src="{{ $url }}"
                alt="{{ $alt }} - image {{ $loop->iteration }}"
                @if (! $loop->first) x-cloak @endif
                x-show="index === {{ $loop->index }}"
                class="absolute inset-0 h-full w-full object-contain"
                @if (! $loop->first) loading="lazy" @endif
            >
        @empty
            <div class="flex h-full w-full flex-col items-center justify-center text-gray-400">
                <x-phosphor-image-duotone class="h-16 w-16" />
                <span class="mt-2 text-sm">No photo</span>
            </div>
        @endforelse

        @if (count($images) > 1)
            <button type="button" @click="prev()" aria-label="Previous image" class="absolute left-0 top-0 z-10 flex h-full items-center px-3 text-gray-700 hover:text-black focus:outline-none">
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-white/80 shadow dark:bg-zinc-900/80 dark:text-white"><x-phosphor-caret-left class="h-4 w-4" /></span>
            </button>
            <button type="button" @click="next()" aria-label="Next image" class="absolute right-0 top-0 z-10 flex h-full items-center px-3 text-gray-700 hover:text-black focus:outline-none">
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-white/80 shadow dark:bg-zinc-900/80 dark:text-white"><x-phosphor-caret-right class="h-4 w-4" /></span>
            </button>
            <div class="absolute bottom-3 left-0 right-0 z-10 flex justify-center space-x-2">
                @foreach ($images as $url)
                    <button type="button" @click="index = {{ $loop->index }}" aria-label="Show image {{ $loop->iteration }}"
                            :class="index === {{ $loop->index }} ? 'bg-gray-900' : 'bg-gray-400/70'" class="h-2.5 w-2.5 rounded-full"></button>
                @endforeach
            </div>
        @endif
    </div>

    @if (count($images) > 1)
        <div class="mt-3 flex gap-2 overflow-x-auto pb-1">
            @foreach ($images as $url)
                <button type="button" @click="index = {{ $loop->index }}" aria-label="Show image {{ $loop->iteration }}"
                        :class="index === {{ $loop->index }} ? 'border-gray-900 dark:border-white' : 'border-gray-200 dark:border-zinc-700'"
                        class="h-16 w-16 flex-shrink-0 overflow-hidden border-2 bg-gray-50">
                    <img src="{{ $url }}" alt="" class="h-full w-full object-cover" loading="lazy">
                </button>
            @endforeach
        </div>
    @endif
</div>
