{{-- A plain card of the public pages: the border, corners and shadow of the design / driver cards. --}}
@props(['padding' => 'p-5'])

<div {{ $attributes->class(['rounded-lg border-2 border-gray-100 bg-white shadow-sm', $padding]) }}>
    {{ $slot }}
</div>
