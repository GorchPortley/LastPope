{{--
    A collapsible section in the style of .sd-section-head: a big condensed title over a 2 px ink rule, a square
    chevron button on the right. The content opens below it.
--}}
@props(['title', 'open' => true, 'count' => null])

<div x-data="{ isOpen: @js((bool) $open) }" {{ $attributes->class(['pt-2']) }}>
    <button type="button" @click="isOpen = !isOpen" :aria-expanded="isOpen" class="sd-section-head w-full text-left">
        <span class="sd-title text-2xl sm:text-[28px]">{{ $title }}@if ($count !== null) <span class="ml-1 font-mono text-sm font-normal normal-case tracking-normal text-ink-2">({{ $count }})</span>@endif</span>
        <svg class="h-5 w-5 text-ink transition-transform" :class="{ 'rotate-180': !isOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
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
