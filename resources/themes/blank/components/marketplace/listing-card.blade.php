@props(['listing'])

@php
    $photo = $listing->mainPhotoUrl();
    $shop = $listing->seller?->sellerProfile?->shop_name ?: $listing->seller?->name;
@endphp

<a href="{{ route('listing', ['uuid' => $listing->uuid]) }}" {{ $attributes->class(['group flex h-full flex-col overflow-hidden border border-gray-200 bg-white shadow-sm transition hover:shadow-lg dark:border-zinc-700 dark:bg-zinc-800']) }}>
    <div class="relative aspect-[4/3] w-full overflow-hidden bg-gray-50 dark:bg-zinc-900">
        @if ($photo)
            <img src="{{ $photo }}" alt="{{ $listing->title }}" loading="lazy" class="h-full w-full object-cover transition group-hover:scale-105">
        @else
            <div class="flex h-full w-full items-center justify-center text-gray-300"><x-phosphor-image-duotone class="h-14 w-14" /></div>
        @endif
        <div class="absolute left-2 top-2 flex flex-wrap gap-1">
            <x-marketplace.badge :color="$listing->isNew() ? 'success' : 'warning'" class="bg-white/95 dark:bg-zinc-900/95">{{ $listing->condition->label() }}</x-marketplace.badge>
        </div>
        @if ($listing->isLot())
            <div class="absolute right-2 top-2">
                <x-marketplace.badge color="primary" class="bg-white/95 dark:bg-zinc-900/95">{{ $listing->units_per_lot === 2 ? 'Pair' : 'Set of '.$listing->units_per_lot }}</x-marketplace.badge>
            </div>
        @endif
    </div>
    <div class="flex flex-1 flex-col p-4">
        <h3 class="line-clamp-2 font-semibold text-gray-900 dark:text-white">{{ $listing->title }}</h3>
        <p class="mt-1 line-clamp-1 text-sm text-gray-500 dark:text-zinc-400">{{ $shop }}</p>
        <div class="mt-auto flex items-end justify-between gap-2 pt-3">
            <div>
                <span class="text-lg font-bold text-gray-900 dark:text-white">{{ money($listing->price_cents) }}</span>
                @if ($listing->isLot())
                    <span class="text-xs text-gray-500 dark:text-zinc-400">per {{ $listing->units_per_lot === 2 ? 'pair' : 'set' }}</span>
                @endif
            </div>
            <span class="text-xs text-gray-500 dark:text-zinc-400">Ships from {{ $listing->ships_from_country }}</span>
        </div>
    </div>
</a>
