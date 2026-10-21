@props(['color' => 'gray'])

@php
    $classes = match ($color) {
        'success' => 'bg-green-50 text-green-700 ring-green-600/20',
        'warning' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        'danger' => 'bg-red-50 text-red-700 ring-red-600/20',
        'info' => 'bg-blue-50 text-blue-700 ring-blue-600/20',
        'primary' => 'bg-purple-50 text-purple-800 ring-purple-600/20',
        default => 'bg-gray-50 text-gray-700 ring-gray-500/20',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center whitespace-nowrap rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $classes]) }}>{{ $slot }}</span>
