{{--
    The "Specifications" block of the design and driver pages: a two-column dl of small grey labels over
    medium values. `items` is label => value; blank values are skipped.
--}}
@props(['items' => [], 'title' => 'Specifications'])

<div {{ $attributes->twMerge('border-t border-gray-200 pt-6') }}>
    @if ($title)
        <h2 class="text-xl font-semibold text-gray-900 mb-4">{{ $title }}</h2>
    @endif
    <dl class="grid grid-cols-2 gap-x-6 gap-y-4">
        @foreach ($items as $label => $value)
            @continue(blank($value) && $value !== 0)
            <div class="min-w-0">
                <dt class="text-sm text-gray-500">{{ $label }}</dt>
                <dd class="mt-1 text-lg font-medium text-gray-900 break-words">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
</div>
