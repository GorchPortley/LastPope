{{-- $variant: "default" (marketing-style footer with the site links) or "business" (supplier mail), see footer.blade.php --}}
@props(['variant' => 'default', 'label' => 'Purchase order'])
@component('mail::layout')
    {{-- Header --}}
    @slot('header')
        @component('mail::header', ['url' => config('app.url'), 'variant' => $variant, 'label' => $label])
            {{ config('app.name') }}
        @endcomponent
    @endslot

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        @slot('subcopy')
            @component('mail::subcopy')
                {{ $subcopy }}
            @endcomponent
        @endslot
    @endisset

    {{-- Footer --}}
    @slot('footer')
        @component('mail::footer', ['variant' => $variant])
        @endcomponent
    @endslot
@endcomponent
