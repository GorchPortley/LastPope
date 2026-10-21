{{-- Small picture of a purchasable: the first listing photo, or the first card image of a design/driver. --}}
@props(['purchasable' => null])

@php
    $url = null;

    if ($purchasable instanceof \App\Models\Listing) {
        $url = $purchasable->mainPhotoUrl();
    } elseif ($purchasable instanceof \App\Models\Design || $purchasable instanceof \App\Models\Driver) {
        $image = is_array($purchasable->card_image) ? ($purchasable->card_image[0] ?? null) : $purchasable->card_image;
        $url = $image ? \Illuminate\Support\Facades\Storage::url($image) : null;
    }
@endphp

<div {{ $attributes->class(['flex h-20 w-20 flex-shrink-0 items-center justify-center overflow-hidden border border-gray-200 bg-gray-50 dark:border-zinc-700 dark:bg-zinc-900']) }}>
    @if ($url)
        <img src="{{ $url }}" alt="" loading="lazy" class="h-full w-full object-contain">
    @else
        <x-phosphor-image-duotone class="h-8 w-8 text-gray-300" />
    @endif
</div>
