{{--
    Empty state for the public pages: a square icon tile, a condensed heading and a short grey line. Put buttons in the
    slot. `bordered` draws the sheet around it.
--}}
@props(['heading', 'description' => null, 'icon' => 'phosphor-storefront', 'bordered' => true])

<div {{ $attributes->class(['px-6 py-12', 'border border-rule bg-white' => $bordered]) }}>
    <div class="mx-auto grid max-w-lg justify-items-center text-center">
        <div class="mb-4 border border-rule bg-paper p-3">
            <x-filament::icon :icon="$icon" class="h-6 w-6 text-ink-2" />
        </div>
        <h2 class="font-condensed text-xl font-bold uppercase leading-tight text-ink">{{ $heading }}</h2>
        @if ($description)
            <p class="mt-1 text-sm text-ink-2">{{ $description }}</p>
        @endif
        @if (! \Filament\Support\is_slot_empty($slot))
            <div class="mt-6 flex flex-wrap justify-center gap-3">{{ $slot }}</div>
        @endif
    </div>
</div>
