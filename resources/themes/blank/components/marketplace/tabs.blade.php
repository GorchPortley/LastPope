{{--
    Tab bar in the style of the design page tabs (Summary / Description / ...): a thin rule under the row, the
    active tab bold with a black underline. These tabs are links (a plain GET filter), `items` is
    key => [label, url]; `active` is the key of the current one.
--}}
@props(['items' => [], 'active' => null, 'label' => 'tab options'])

<div class="flex gap-2 overflow-x-auto border-b border-neutral-300" role="tablist" aria-label="{{ $label }}">
    @foreach ($items as $key => [$text, $url])
        <a href="{{ $url }}" role="tab" aria-selected="{{ (string) $key === (string) $active ? 'true' : 'false' }}"
           @class([
               'h-min whitespace-nowrap px-4 py-2 text-sm',
               'font-bold text-black border-b-2 border-black' => (string) $key === (string) $active,
               'text-neutral-600 font-medium hover:border-b-2 hover:border-b-neutral-800 hover:text-neutral-900' => (string) $key !== (string) $active,
           ])>{{ $text }}</a>
    @endforeach
</div>
