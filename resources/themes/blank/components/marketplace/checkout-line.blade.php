{{-- One line of the checkout order summary. $line is an App\Services\Marketplace\Data\CartLine. --}}
@props(['line'])

<div wire:key="line-{{ $line->id() }}" data-checkout-line>
    <div class="flex justify-between gap-3 text-gray-900">
        <span class="min-w-0">{{ $line->quantity > 1 ? $line->quantity.' x ' : '' }}{{ $line->title }}</span>
        <span class="flex-shrink-0">{{ money($line->lineTotalCents()) }}</span>
    </div>
    @if ($line->priceChanged())
        <p class="text-amber-700">The price changed from {{ money($line->storedUnitPriceCents) }} to {{ money($line->unitPriceCents) }} since you added it.</p>
    @endif
    @foreach ($line->issues as $issue)
        <p class="text-red-600">{{ $issue->message }}</p>
    @endforeach
</div>
