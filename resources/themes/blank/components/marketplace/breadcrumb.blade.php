{{--
    Breadcrumb and the rule under it, exactly as on the design and driver pages.
    `items` is a list of [label, url]; the last one (or any item without a url) is the current page.
--}}
@props(['items' => [], 'rule' => true])

<nav class="mb-4 pt-3 text-sm" aria-label="Breadcrumb">
    <ol class="flex flex-wrap items-center gap-x-2">
        @foreach ($items as [$label, $url])
            @if ($url && ! $loop->last)
                <li><a href="{{ $url }}" class="text-ink-2 no-underline hover:text-ink hover:underline">{{ $label }}</a></li>
                <li class="text-ink-3" aria-hidden="true">/</li>
            @else
                <li class="min-w-0 text-ink" aria-current="page">{{ $label }}</li>
            @endif
        @endforeach
    </ol>
</nav>
@if ($rule)
    <hr class="mb-6 border-t border-rule">
@endif
