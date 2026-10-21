{{--
    A grid of listing cards, the counterpart of design-card-container and driver-card-container (same grid,
    same gaps). `cols` replaces the column classes when the grid sits in a narrower place than the page.
--}}
@props([
    'listings',
    'cols' => 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5',
    'padded' => true,
])

<div {{ $attributes->class(['grid gap-4', $cols, 'p-4' => $padded]) }}>
    @foreach ($listings as $listing)
        <x-marketing.listing-card :listing="$listing" wire:key="listing-{{ $listing->id }}" />
    @endforeach
</div>
