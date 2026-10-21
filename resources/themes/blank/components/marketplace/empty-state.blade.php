{{--
    Empty state in the Filament table style (round grey icon, semibold heading, small grey text), for the
    public pages. Put buttons in the slot. `bordered` draws the card around it.
--}}
@props(['heading', 'description' => null, 'icon' => 'phosphor-storefront', 'bordered' => true])

<div {{ $attributes->class(['px-6 py-12', 'rounded-lg border-2 border-gray-100 bg-white shadow-sm' => $bordered]) }}>
    <div class="mx-auto grid max-w-lg justify-items-center text-center">
        <div class="mb-4 rounded-full bg-gray-100 p-3">
            <x-filament::icon :icon="$icon" class="h-6 w-6 text-gray-500" />
        </div>
        <h2 class="text-base font-semibold leading-6 text-gray-950">{{ $heading }}</h2>
        @if ($description)
            <p class="mt-1 text-sm text-gray-500">{{ $description }}</p>
        @endif
        @if (! \Filament\Support\is_slot_empty($slot))
            <div class="mt-6 flex flex-wrap justify-center gap-3">{{ $slot }}</div>
        @endif
    </div>
</div>
