{{--
    The heading of one parcel in the cart and the checkout summary (docs/PLAN.md 8.2): where it ships from and what its
    shipping costs. $group is an App\Services\Marketplace\Data\ShippingGroup; its label never names a supplier
    ("Ships from SDLabs", "Ships separately (5–8 business days)"). $note is the shipping line on the right.
--}}
@props(['group', 'note' => null])

<div {{ $attributes->class(['flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-sm']) }} data-parcel="{{ $group->key }}">
    <span class="flex min-w-0 items-center gap-2 font-medium text-gray-800" data-parcel-label>
        <x-phosphor-package class="h-4 w-4 flex-shrink-0 text-gray-500" />
        <span class="min-w-0">{{ $group->label() }}</span>
    </span>
    @if ($note)
        <span class="flex-shrink-0 text-gray-600" data-parcel-shipping>{{ $note }}</span>
    @endif
</div>
