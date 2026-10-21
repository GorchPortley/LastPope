{{--
    Breadcrumb and the rule under it, exactly as on the design and driver pages.
    `items` is a list of [label, url]; the last one (or any item without a url) is the current page.
--}}
@props(['items' => [], 'rule' => true])

<nav class="mb-6" aria-label="Breadcrumb">
    <ol class="flex flex-wrap items-center gap-x-2">
        @foreach ($items as [$label, $url])
            @if ($url && ! $loop->last)
                <li><a href="{{ $url }}" class="text-gray-500 hover:text-gray-700">{{ $label }}</a></li>
                <li class="text-gray-400" aria-hidden="true">/</li>
            @else
                <li class="min-w-0 text-gray-900" aria-current="page">{{ $label }}</li>
            @endif
        @endforeach
    </ol>
</nav>
@if ($rule)
    <hr class="mb-8 border-t-2 border-gray-300">
@endif
