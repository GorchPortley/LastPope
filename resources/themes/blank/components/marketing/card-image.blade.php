{{--
    The picture of a design, driver or listing card: a 480 px WebP thumbnail (960 px on high-density screens) made on
    first use by App\Support\Thumbnails, loaded lazily. A missing picture falls back to the placeholder once.
    `fit` is "contain" (drivers, other callers) or "cover" (design and listing photos that fill their frame).
--}}
@props(['path' => null, 'alt' => '', 'fit' => 'contain'])

@php
    $src = \App\Support\Thumbnails::url($path, 480);
    $srcset = \App\Support\Thumbnails::srcset($path, [480, 960]);
    $fallback = \App\Support\VersionedAsset::url('images/blog/post-placeholder.svg');
@endphp

<img
    src="{{ $src ?? $fallback }}"
    @if ($srcset !== '') srcset="{{ $srcset }}" sizes="(min-width: 640px) 320px, 100vw" @endif
    alt="{{ $alt }}"
    loading="lazy"
    decoding="async"
    {{ $attributes->class([
        'max-h-full max-w-full object-contain' => $fit !== 'cover',
        'block h-full w-full object-cover' => $fit === 'cover',
    ]) }}
    onerror="this.onerror=null; this.removeAttribute('srcset'); this.src='{{ $fallback }}'"
>
