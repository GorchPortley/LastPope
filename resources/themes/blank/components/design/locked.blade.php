{{--
    Stands in for a part of a paid design that only buyers see (description, drivers, parts list). The content itself is
    never sent to the browser; this says what is there and leads to the buy box.
--}}
@props(['what' => ''])

<div {{ $attributes->class(['flex flex-wrap items-center justify-between gap-x-6 gap-y-3 border border-dashed border-rule bg-paper px-4 py-4']) }} data-design-locked>
    <div class="flex min-w-0 items-start gap-3">
        <x-phosphor-lock-simple class="mt-0.5 h-5 w-5 flex-none text-ink-2" aria-hidden="true" />
        <div class="min-w-0">
            <p class="font-semibold text-ink">Included with the plans</p>
            <p class="text-[15px] text-ink-2">{{ $what }}</p>
        </div>
    </div>
    <a href="#buy" class="sd-btn sd-btn-sm">Get the plans</a>
</div>
