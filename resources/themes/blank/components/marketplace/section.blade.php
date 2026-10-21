{{--
    A collapsible section with the heading style of "File Overview" on the design page:
    border-t, big semibold title over a thick rule, a chevron on the right.
--}}
@props(['title', 'open' => true, 'count' => null])

<div x-data="{ isOpen: @js((bool) $open) }" {{ $attributes->class(['border-t border-gray-200 pt-8']) }}>
    <button type="button" @click="isOpen = !isOpen" :aria-expanded="isOpen" class="flex items-center justify-between w-full text-xl font-semibold text-gray-900 pb-4 border-b-2 border-zinc-400">
        <span>{{ $title }}@if ($count !== null) <span class="text-base font-normal text-gray-500">({{ $count }})</span>@endif</span>
        <svg class="w-6 h-6 transition-transform" :class="{ 'rotate-180': !isOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
        </svg>
    </button>

    <div
        x-show="isOpen"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 -translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        class="pt-4"
    >
        {{ $slot }}
    </div>
</div>
