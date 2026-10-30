{{-- Small picture of a purchasable: the first listing photo, or the first card image of a design. --}}
@props(['purchasable' => null])

@php
    $url = null;

    // 160 px WebP thumbnails (App\Support\Thumbnails) for an 80 px box: sharp on high-density screens, small to load.
    if ($purchasable instanceof \App\Models\Listing) {
        $url = \App\Support\Thumbnails::url($purchasable->photos[0] ?? null, 160);
    } elseif ($purchasable instanceof \App\Models\Design) {
        $image = is_array($purchasable->card_image) ? ($purchasable->card_image[0] ?? null) : $purchasable->card_image;
        $url = \App\Support\Thumbnails::url($image, 160);
    }
@endphp

<div {{ $attributes->class(['flex h-20 w-20 flex-shrink-0 items-center justify-center overflow-hidden border border-gray-200 bg-gray-50']) }}>
    @if ($url)
        <img src="{{ $url }}" alt="" loading="lazy" class="h-full w-full object-contain">
    @else
        <x-phosphor-image-duotone class="h-8 w-8 text-gray-300" />
    @endif
</div>
