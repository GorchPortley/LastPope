@props([
    'level' => 'h1',
    'title' => 'No Heading Title Entered',
    'description' => 'Be sure to include the description attribute',
    'align' => 'center'
])


<div {{ $attributes->class([
        'relative w-full',
        'text-left' => $align == 'left',
        'text-right' => $align == 'right',
        'text-center' => $align != 'left' && $align != 'right'
    ]) }}>
    <{{ $level }} class="sd-title text-balance text-4xl sm:text-center sm:text-5xl lg:text-6xl">{!! $title!!}</{{ $level }}>
    <p class="mt-4 text-base text-ink-2 sm:text-center sm:text-balance sm:text-lg @if($align == 'left'){{ 'ml-auto' }}@elseif($align == 'right'){{ 'mr-auto' }}@else{{ 'mx-auto max-w-2xl' }}@endif">{!! $description !!}</p>
</div>
