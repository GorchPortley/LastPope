{{--
    The specs of a card as stacked label / value rows, in the body face with aligned digits (easier to read than one
    line of monospaced text). `rows` is a list of [label, value]; rows with an empty value are left out.
--}}
@props(['rows' => []])

@php($rows = array_values(array_filter($rows, fn ($row) => filled($row[1] ?? null))))

@if ($rows !== [])
    <dl {{ $attributes->class(['grid text-sm leading-snug']) }} data-card-specs>
        @foreach ($rows as [$label, $value])
            <div class="flex items-baseline justify-between gap-3 border-b border-hair py-1 last:border-b-0">
                <dt class="text-ink-3">{{ $label }}</dt>
                <dd class="text-right font-medium tabular-nums text-ink">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
@endif
