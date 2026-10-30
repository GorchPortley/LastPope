{{-- Five square-cornered stars and the count, for the cards. `average` 0 means no ratings yet. --}}
@props(['average' => 0, 'count' => 0])

<span {{ $attributes->class(['inline-flex items-center gap-1.5 font-mono text-xs text-ink-2']) }}>
    @if ($average > 0)
        <span class="inline-flex" role="img" aria-label="Rated {{ number_format($average, 1) }} out of 5">
            @for ($i = 1; $i <= 5; $i++)
                <svg class="h-3 w-3 {{ $i <= round($average) ? 'text-accent-500' : 'text-hair' }}" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 1.5l2.6 5.6 6.1.7-4.5 4.2 1.2 6L10 14.9 4.6 18l1.2-6L1.3 7.8l6.1-.7L10 1.5z"/></svg>
            @endfor
        </span>
        <span>({{ $count }})</span>
    @else
        <span class="text-ink-3">No ratings</span>
    @endif
</span>
