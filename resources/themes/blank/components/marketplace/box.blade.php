{{-- A plain sheet of the public pages: white, 1 px rule border, square, no shadow. --}}
@props(['padding' => 'p-5'])

<div {{ $attributes->class(['border border-rule bg-white', $padding]) }}>
    {{ $slot }}
</div>
