{{--
    A grid of listing cards, the counterpart of design-card-container and driver-card-container (same grid,
    same gaps). `cols` replaces the column classes when the grid sits in a narrower place than the page.
--}}
@props([
    'listings',
    'cols' => 'grid-cols-[repeat(auto-fill,minmax(250px,1fr))]',
    'padded' => true,
    'listName' => 'Marketplace',
])

<x-analytics.events :events="count($listings) ? [\App\Support\Analytics\Ga4::itemList($listName, $listings)] : []" />

<div {{ $attributes->class(['grid gap-[18px]', $cols, 'p-4' => $padded]) }}>
    @foreach ($listings as $listing)
        <x-marketing.listing-card :listing="$listing" :list-name="$listName" wire:key="listing-{{ $listing->id }}" />
    @endforeach
</div>
