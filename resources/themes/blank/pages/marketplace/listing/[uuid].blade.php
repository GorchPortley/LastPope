<?php

use App\Models\Listing;
use App\Support\Countries;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use function Laravel\Folio\{name, render};

name('listing');

render(function (View $view, string $uuid) {
    $listing = Listing::query()->with(['seller.sellerProfile', 'driver'])->where('uuid', $uuid)->firstOrFail();

    abort_unless(Gate::allows('view', $listing), 404);

    $user = auth()->user();
    $buyerCountry = $user?->addresses()->orderByDesc('is_default')->orderBy('id')->value('country');

    return $view->with([
        'listing' => $listing,
        'seller' => $listing->seller,
        'shop' => $listing->seller?->sellerProfile,
        'driver' => $listing->driver?->active ? $listing->driver : null,
        'buyerCountry' => $buyerCountry,
        'moreFromSeller' => Listing::query()->marketplace()->forSale()->with(['seller.sellerProfile'])
            ->where('seller_id', $listing->seller_id)->whereKeyNot($listing->getKey())->latest()->limit(4)->get(),
    ]);
});
?>

@php
    $shopName = $shop?->shop_name ?: $seller?->name;
    $shopUrl = $shop ? route('seller', ['slug' => $shop->slug]) : null;
    $isOwner = auth()->check() && auth()->id() === $listing->seller_id;
    $photos = $listing->photoUrls();
    $lotWord = $listing->units_per_lot === 2 ? 'pair' : 'set';
    $returns = $listing->returns_accepted ? 'Returns accepted' : 'No returns';
    $quote = $buyerCountry ? $listing->shippingCentsFor($buyerCountry, 1) : null;
@endphp

<x-layouts.marketing :seo="[
    'title' => $listing->title.' - '.config('app.name'),
    'description' => Str::limit(strip_tags((string) $listing->description) ?: $listing->title, 160),
    'image' => $photos[0] ?? url('/og_image.png'),
    'type' => 'product',
]">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <nav class="mb-6 text-sm" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-x-2 text-gray-500 dark:text-zinc-400">
                <li><a href="{{ route('marketplace') }}" class="hover:text-gray-800 dark:hover:text-white">Marketplace</a></li>
                <li aria-hidden="true">/</li>
                <li class="font-medium text-gray-900 dark:text-white">{{ $listing->title }}</li>
            </ol>
        </nav>

        @if ($listing->status !== \App\Enums\ListingStatus::ACTIVE)
            <x-marketplace.notice :level="$isOwner ? 'warning' : 'info'" class="mb-6">
                @if ($isOwner)
                    This listing is <strong>{{ strtolower($listing->status->label()) }}</strong>, so shoppers cannot see it in the marketplace.
                    <a href="{{ route('dashboard.listings') }}">Manage your listings</a>.
                @else
                    This listing is {{ strtolower($listing->status->label()) }} and cannot be bought right now.
                @endif
            </x-marketplace.notice>
        @endif

        <div class="gap-8 lg:grid lg:grid-cols-2">
            <x-marketplace.gallery :images="$photos" :alt="$listing->title" class="mb-8 lg:mb-0" />

            <div class="space-y-6">
                <div>
                    <div class="mb-2 flex flex-wrap gap-2">
                        <x-marketplace.badge :color="$listing->isNew() ? 'success' : 'warning'">{{ $listing->condition->label() }}</x-marketplace.badge>
                        @if ($listing->isLot())
                            <x-marketplace.badge color="primary">Sold as a {{ $lotWord }} ({{ $listing->units_per_lot }} units)</x-marketplace.badge>
                        @endif
                    </div>
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-white">{{ $listing->title }}</h1>
                    <p class="mt-2 text-sm text-gray-600 dark:text-zinc-400">
                        Sold by @if ($shopUrl)<a href="{{ $shopUrl }}" class="font-medium text-purple-800 underline hover:text-purple-950 dark:text-purple-300">{{ $shopName }}</a>@else<span class="font-medium">{{ $shopName }}</span>@endif
                        &middot; Ships from {{ Countries::name($listing->ships_from_country) }}
                    </p>
                </div>

                <livewire:buy-box :purchasable="$listing" />

                <dl class="grid grid-cols-2 gap-4 border border-gray-200 bg-white p-4 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <div>
                        <dt class="text-gray-500 dark:text-zinc-400">Condition</dt>
                        <dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ $listing->condition->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-zinc-400">Available</dt>
                        <dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ $listing->availableQuantity(auth()->user()) }} {{ $listing->isLot() ? $lotWord.'s' : 'in stock' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-zinc-400">Handling time</dt>
                        <dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ $listing->handling_days }} {{ Str::plural('day', $listing->handling_days) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-zinc-400">Returns</dt>
                        <dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ $returns }}</dd>
                    </div>
                </dl>

                <div class="border border-gray-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="flex items-start gap-3">
                        <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center bg-purple-950 text-lg font-bold text-white">{{ Str::upper(Str::substr((string) $shopName, 0, 1)) }}</div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-zinc-400">Seller</p>
                            @if ($shopUrl)<a href="{{ $shopUrl }}" class="block truncate font-semibold text-gray-900 hover:underline dark:text-white">{{ $shopName }}</a>@else<span class="block truncate font-semibold text-gray-900 dark:text-white">{{ $shopName }}</span>@endif
                            @if ($shop?->country)
                                <p class="text-sm text-gray-600 dark:text-zinc-400">{{ Countries::name($shop->country) }}</p>
                            @endif
                        </div>
                    </div>
                    @unless ($isOwner)
                        <div class="mt-4 flex flex-wrap gap-2">
                            <x-button tag="a" :href="auth()->check() ? url('/chatify/'.$listing->seller_id) : route('login')" color="gray">
                                <x-phosphor-chat-circle class="mr-1 inline h-4 w-4" /> Contact seller
                            </x-button>
                            @if ($shopUrl)
                                <x-button tag="a" :href="$shopUrl" color="gray">Visit shop</x-button>
                            @endif
                        </div>
                    @endunless
                </div>
            </div>
        </div>

        <div class="mt-10 grid gap-8 lg:grid-cols-3">
            <div class="space-y-8 lg:col-span-2">
                <section>
                    <h2 class="mb-3 border-b-2 border-zinc-300 pb-2 text-xl font-semibold text-gray-900 dark:border-zinc-600 dark:text-white">Description</h2>
                    @if (filled($listing->description))
                        <x-safe-html-renderer :content="(string) $listing->description" />
                    @else
                        <p class="text-gray-500 dark:text-zinc-400">The seller did not add a description.</p>
                    @endif
                </section>

                @if ($driver)
                    <section>
                        <h2 class="mb-3 border-b-2 border-zinc-300 pb-2 text-xl font-semibold text-gray-900 dark:border-zinc-600 dark:text-white">About this driver</h2>
                        <div class="flex flex-col gap-4 border border-gray-200 bg-white p-4 sm:flex-row dark:border-zinc-700 dark:bg-zinc-800">
                            @php($driverImage = is_array($driver->card_image) ? ($driver->card_image[0] ?? null) : $driver->card_image)
                            @if ($driverImage)
                                <img src="{{ Storage::url($driverImage) }}" alt="{{ $driver->fullName() }}" class="h-32 w-32 flex-shrink-0 object-contain" loading="lazy">
                            @endif
                            <div class="min-w-0 flex-1">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $driver->fullName() }}</h3>
                                <p class="text-sm text-gray-600 dark:text-zinc-400">{{ $driver->category }} &middot; {{ $driver->size }}" &middot; {{ $driver->impedance }}&Omega; &middot; {{ $driver->power }}W</p>
                                @if ($driver->msrp_cents)
                                    <p class="mt-1 text-sm text-gray-600 dark:text-zinc-400">New retail price: {{ money($driver->msrp_cents) }}</p>
                                @endif
                                @if (! empty($driver->factory_specs))
                                    <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-3">
                                        @foreach (array_slice($driver->factory_specs, 0, 9, true) as $key => $value)
                                            @continue(blank($value))
                                            <div class="flex justify-between gap-2 border-b border-gray-100 py-1 dark:border-zinc-700">
                                                <dt class="text-gray-500 dark:text-zinc-400">{{ $key }}</dt>
                                                <dd class="font-medium text-gray-900 dark:text-white">{{ $value }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <x-button tag="a" :href="route('driver', ['id' => $driver->id])" color="gray" size="sm">Full specs and measurements</x-button>
                                    <x-button tag="a" :href="route('marketplace', ['driver' => $driver->id])" color="gray" size="sm">Other listings for this driver</x-button>
                                </div>
                            </div>
                        </div>
                    </section>
                @endif
            </div>

            <aside class="space-y-8">
                <section>
                    <h2 class="mb-3 border-b-2 border-zinc-300 pb-2 text-xl font-semibold text-gray-900 dark:border-zinc-600 dark:text-white">Shipping</h2>
                    <table class="w-full text-sm" data-shipping-table>
                        <tbody class="divide-y divide-gray-200 dark:divide-zinc-700">
                            <tr>
                                <th scope="row" class="py-2 pr-3 text-left font-normal text-gray-600 dark:text-zinc-400">Ships from</th>
                                <td class="py-2 text-right font-medium text-gray-900 dark:text-white">{{ Countries::name($listing->ships_from_country) }}</td>
                            </tr>
                            <tr>
                                <th scope="row" class="py-2 pr-3 text-left font-normal text-gray-600 dark:text-zinc-400">Domestic ({{ $listing->ships_from_country }})</th>
                                <td class="py-2 text-right font-medium text-gray-900 dark:text-white">{{ $listing->shipping_domestic_cents > 0 ? money($listing->shipping_domestic_cents) : 'Free' }}</td>
                            </tr>
                            <tr>
                                <th scope="row" class="py-2 pr-3 text-left font-normal text-gray-600 dark:text-zinc-400">International</th>
                                <td class="py-2 text-right font-medium text-gray-900 dark:text-white">
                                    {{ $listing->shipping_international_cents === null ? 'Not available' : ($listing->shipping_international_cents > 0 ? money($listing->shipping_international_cents) : 'Free') }}
                                </td>
                            </tr>
                            <tr>
                                <th scope="row" class="py-2 pr-3 text-left font-normal text-gray-600 dark:text-zinc-400">Each additional item</th>
                                <td class="py-2 text-right font-medium text-gray-900 dark:text-white">{{ $listing->additional_item_shipping_cents > 0 ? '+ '.money($listing->additional_item_shipping_cents) : 'No extra cost' }}</td>
                            </tr>
                            <tr>
                                <th scope="row" class="py-2 pr-3 text-left font-normal text-gray-600 dark:text-zinc-400">Handling time</th>
                                <td class="py-2 text-right font-medium text-gray-900 dark:text-white">{{ $listing->handling_days }} {{ Str::plural('day', $listing->handling_days) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    @if ($buyerCountry)
                        <p class="mt-3 text-sm text-gray-700 dark:text-zinc-300">
                            @if ($quote === null)
                                The seller does not ship to {{ Countries::name($buyerCountry) }}.
                            @else
                                To your address in {{ Countries::name($buyerCountry) }}: <strong>{{ $quote > 0 ? money($quote) : 'free shipping' }}</strong> for one item.
                            @endif
                        </p>
                    @endif
                </section>

                <section>
                    <h2 class="mb-3 border-b-2 border-zinc-300 pb-2 text-xl font-semibold text-gray-900 dark:border-zinc-600 dark:text-white">Returns</h2>
                    <p class="text-sm text-gray-700 dark:text-zinc-300">
                        @if ($listing->returns_accepted)
                            {{ filled($listing->return_policy) ? $listing->return_policy : 'The seller accepts returns. Contact them to arrange it.' }}
                        @else
                            This seller does not accept returns. Ask a question before you buy.
                        @endif
                    </p>
                </section>
            </aside>
        </div>

        @if ($moreFromSeller->isNotEmpty())
            <section class="mt-12">
                <h2 class="mb-4 text-xl font-semibold text-gray-900 dark:text-white">More from {{ $shopName }}</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($moreFromSeller as $other)
                        <x-marketplace.listing-card :listing="$other" />
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-layouts.marketing>
