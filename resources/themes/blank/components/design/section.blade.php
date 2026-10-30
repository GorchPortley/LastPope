{{--
    One sheet of the design page: a header row (title, optional right-hand content) and a padded body.
    `id` becomes the anchor "sec-{id}" that the sticky row of section links points to.
--}}
@props(['id', 'title', 'hint' => null])

<section id="sec-{{ $id }}" {{ $attributes->class(['sd-sheet mb-5 scroll-mt-14']) }}>
    <header class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-rule px-4 py-3 max-sm:px-3">
        <h2 class="sd-title text-[22px]">{{ $title }}</h2>
        @isset($header)
            {{ $header }}
        @elseif ($hint)
            <p class="text-[13px] text-ink-3">{{ $hint }}</p>
        @endisset
    </header>
    <div class="p-4 max-sm:p-3">
        {{ $slot }}
    </div>
</section>
