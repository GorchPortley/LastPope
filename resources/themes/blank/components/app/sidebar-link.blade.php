<a
    {{ $attributes->except(['class']) }}
    @class([
        'block w-full cursor-pointer px-3 py-2 text-[14px] font-medium leading-tight text-ink-2 no-underline hover:bg-paper hover:text-ink',
        'bg-paper font-semibold text-ink shadow-[inset_3px_0_0_rgb(var(--brand-800))]' => ($active ?? false) == true,
    ])
>{{ $slot }}</a>
