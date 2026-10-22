{{--
    One line of the cart: thumbnail, title, price, quantity stepper and remove. Lives inside the cart Volt component
    (wire:click / wire:change act on it). $line is an App\Services\Marketplace\Data\CartLine.
--}}
@props(['line'])

@php
    $purchasable = $line->purchasable;
    $isStore = $purchasable instanceof \App\Models\Listing && $purchasable->isStore();
@endphp

<li class="flex gap-4 p-4" wire:key="line-{{ $line->id() }}" data-cart-line="{{ $line->id() }}">
    <x-marketplace.thumb :purchasable="$purchasable" class="rounded-md" />

    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
            <h2 class="min-w-0 text-lg font-semibold text-gray-900">
                @if ($purchasable instanceof \App\Models\Design)
                    <a href="{{ route('design', ['id' => $purchasable->getKey()]) }}" class="hover:text-link-400">{{ $line->title }}</a>
                @elseif ($purchasable instanceof \App\Models\Listing)
                    <a href="{{ $purchasable->isStore() && $purchasable->driver_id ? route('driver', ['id' => $purchasable->driver_id]) : route('listing', ['uuid' => $purchasable->uuid]) }}" class="hover:text-link-400">{{ $line->title }}</a>
                @else
                    {{ $line->title }}
                @endif
            </h2>
            <p class="text-lg font-semibold text-gray-900">{{ money($line->lineTotalCents()) }}</p>
        </div>

        <p class="mt-0.5 text-sm text-gray-500">
            @if ($line->isDigital)
                Digital download
            @elseif ($isStore && $line->fulfillment)
                {{-- when this quantity ships: "In stock, ships in 3 days" / "Ships in 5–8 business days" (never the supplier) --}}
                <span data-line-shipping>{{ $line->fulfillment->shippingText() }}</span>
            @else
                Physical item, shipped by the seller
            @endif
            @if (! $line->isDigital && $purchasable instanceof \App\Models\Listing)
                &middot; {{ $purchasable->condition->label() }}
            @endif
            &middot; {{ money($line->unitPriceCents) }} each
        </p>

        @if ($line->priceChanged())
            <p class="mt-1 text-sm font-medium text-amber-700">
                The price changed from {{ money($line->storedUnitPriceCents) }} to {{ money($line->unitPriceCents) }} since you added it.
            </p>
        @endif
        @foreach ($line->issues as $issue)
            <p class="mt-1 text-sm font-medium text-red-600">{{ $issue->message }}</p>
        @endforeach

        <div class="mt-3 flex flex-wrap items-center gap-4">
            @if (! $line->isDigital && $purchasable)
                <div class="inline-flex items-center overflow-hidden rounded-md border border-gray-300">
                    <button type="button" wire:click="updateQuantity({{ $line->id() }}, {{ $line->quantity - 1 }})" class="px-2.5 py-1.5 text-gray-700 hover:bg-gray-100" aria-label="Decrease quantity">&minus;</button>
                    <input type="number" min="1" @if($line->maxQuantity) max="{{ $line->maxQuantity }}" @endif
                           value="{{ $line->quantity }}"
                           wire:change="updateQuantity({{ $line->id() }}, $event.target.value)"
                           aria-label="Quantity"
                           class="w-14 appearance-none border-0 border-x border-gray-300 bg-transparent px-1 py-1.5 text-center text-sm focus:ring-0">
                    <button type="button" wire:click="updateQuantity({{ $line->id() }}, {{ $line->quantity + 1 }})" class="px-2.5 py-1.5 text-gray-700 hover:bg-gray-100" aria-label="Increase quantity">+</button>
                </div>
            @endif
            <button type="button" wire:click="removeItem({{ $line->id() }})" class="text-sm font-medium text-red-600 hover:text-red-800">
                Remove
            </button>
        </div>
    </div>
</li>
