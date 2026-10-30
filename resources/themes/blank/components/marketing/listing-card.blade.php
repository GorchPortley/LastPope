{{--
    One marketplace listing as a card, modelled on the cards of design-card-container: image on top, centred
    name / "By" / tag lines, a small spec table, View + Specs buttons and the sliding specs panel.
    Eager-load seller.sellerProfile, driver and design to keep the grid free of extra queries.
--}}
@props(['listing', 'listName' => 'Marketplace'])

@php
    $photo = $listing->photos[0] ?? null;
    $shop = $listing->seller?->sellerProfile?->shop_name ?: $listing->seller?->name;
    $url = route('listing', ['uuid' => $listing->uuid]);
    $select = \App\Support\Analytics\Ga4::selectItem($listName, $listing);
    $tag = match (true) {
        $listing->design !== null => 'Built from '.$listing->design->name,
        $listing->driver !== null => $listing->driver->fullName(),
        default => $listing->category->label(),
    };
    $soldAs = $listing->isLot() ? ($listing->units_per_lot === 2 ? 'Pair' : 'Set of '.$listing->units_per_lot) : 'Single item';
    $domestic = $listing->shipping_domestic_cents > 0 ? money($listing->shipping_domestic_cents) : 'Free';
@endphp

<div x-data="{ showSpecs: false }" {{ $attributes->class(['relative flex flex-col bg-white rounded-lg border-2 border-gray-100 hover:shadow-lg shadow-sm overflow-hidden h-full']) }} data-listing-card="{{ $listing->uuid }}">
    <!-- Listing image section -->
    <div class="relative pt-4 px-4">
        <div class="absolute left-6 top-6 max-w-[60%] truncate bg-brand-950 text-white text-xs font-semibold px-2 py-1 rounded-full z-10">{{ $listing->category->label() }}</div>
        @if ($listing->isLot())
            <div class="absolute right-6 top-6 bg-yellow-400 text-white text-xs font-semibold px-2 py-1 rounded-full z-10">{{ $soldAs }}</div>
        @endif

        <a href="{{ $url }}" data-ga4-click="{{ $select }}" class="block w-full">
            <div class="flex justify-center items-center h-48 md:h-56">
                @if ($photo)
                    <x-marketing.card-image :path="$photo" :alt="$listing->title" />
                @else
                    <x-phosphor-image-duotone class="h-16 w-16 text-gray-300" />
                @endif
            </div>
        </a>
    </div>

    <!-- Listing details section -->
    <div class="flex flex-col flex-grow p-4">
        <h2 class="text-lg font-semibold text-center mb-1 line-clamp-2 min-h-[3.5rem]">{{ $listing->title }}</h2>
        <p class="text-gray-600 text-center text-sm mb-1 line-clamp-1">By: {{ $shop }}</p>
        <p class="text-gray-800 text-center text-sm mb-3 line-clamp-1">{{ $tag }}</p>

        <!-- Specs table -->
        <div class="mt-auto">
            <table class="w-full mb-4 text-sm">
                <tbody>
                    <tr>
                        <td class="py-1 font-medium">Condition</td>
                        <td class="text-gray-600 text-right">{{ $listing->condition->label() }}</td>
                    </tr>
                    <tr>
                        <td class="py-1 font-medium">Price</td>
                        <td class="text-gray-600 text-right">{{ money($listing->price_cents) }}@if ($listing->isLot()) <span class="text-xs">per {{ $listing->units_per_lot === 2 ? 'pair' : 'set' }}</span>@endif</td>
                    </tr>
                    <tr>
                        <td class="py-1 font-medium whitespace-nowrap">Ships from</td>
                        <td class="text-gray-600 text-right">{{ $listing->ships_from_country }}</td>
                    </tr>
                    <tr>
                        <td class="py-1 font-medium">In stock</td>
                        <td class="text-gray-600 text-right">{{ $listing->quantity }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Button section -->
        <div class="flex mt-2 gap-2">
            <x-button tag="a" href="{{ $url }}" data-ga4-click="{{ $select }}" class="flex-grow">View</x-button>
            <x-button
                @click="showSpecs = true"
                x-show="!showSpecs"
                color="success"
                class="px-3 whitespace-nowrap">
                Specs
            </x-button>
        </div>
    </div>

    <!-- Sliding specs panel -->
    <div
        x-show="showSpecs"
        x-cloak
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="translate-y-full"
        x-transition:enter-end="translate-y-0"
        x-transition:leave="transition ease-in duration-300"
        x-transition:leave-start="translate-y-0"
        x-transition:leave-end="translate-y-full"
        class="absolute inset-0 bg-white shadow-lg flex flex-col">

        <div class="p-4 flex-grow overflow-auto">
            <h3 class="text-lg font-semibold text-center mb-4 line-clamp-2">{{ $listing->title }} Specifications</h3>

            <table class="w-full border-collapse text-sm">
                <tbody>
                    <tr class="border-b">
                        <td class="py-2 font-medium">Condition</td>
                        <td class="py-2 text-right">{{ $listing->condition->label() }}</td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 font-medium">Sold as</td>
                        <td class="py-2 text-right">{{ $soldAs }}</td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 font-medium">In stock</td>
                        <td class="py-2 text-right">{{ $listing->quantity }}</td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 font-medium">Shipping ({{ $listing->ships_from_country }})</td>
                        <td class="py-2 text-right">{{ $domestic }}</td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 font-medium">Handling time</td>
                        <td class="py-2 text-right">{{ $listing->handling_days }} {{ \Illuminate\Support\Str::plural('day', $listing->handling_days) }}</td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 font-medium">Returns</td>
                        <td class="py-2 text-right">{{ $listing->returns_accepted ? 'Accepted' : 'No returns' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="p-4 pt-0">
            <div class="flex gap-2">
                <x-button tag="a" href="{{ $url }}" data-ga4-click="{{ $select }}" class="flex-grow">View Details</x-button>
                <x-button @click="showSpecs = false" color="danger" class="flex-grow">Close</x-button>
            </div>
        </div>
    </div>
</div>
