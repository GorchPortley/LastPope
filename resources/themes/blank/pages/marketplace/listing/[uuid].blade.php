<?php

use App\Enums\ListingCategory;
use App\Models\Listing;
use App\Support\Countries;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use function Laravel\Folio\{name, render};

name('listing');

render(function (View $view, string $uuid) {
    $listing = Listing::query()->with(['seller.sellerProfile', 'driver', 'design.designer'])->where('uuid', $uuid)->firstOrFail();

    abort_unless(Gate::allows('view', $listing), 404);

    // SDLabs' own new drivers are sold from their driver page: a store listing has no page of its own.
    if ($listing->isStore()) {
        abort_if($listing->driver_id === null, 404);

        return redirect()->route('driver', ['id' => $listing->driver_id], 301);
    }

    $user = auth()->user();
    $buyerCountry = $user?->addresses()->orderByDesc('is_default')->orderBy('id')->value('country');

    return $view->with([
        'listing' => $listing,
        'seller' => $listing->seller,
        'shop' => $listing->seller?->sellerProfile,
        // Kits and finished speakers: the design they are built from. Used gear: the catalogue driver.
        'design' => $listing->category !== ListingCategory::USED_GEAR && $listing->design?->active ? $listing->design : null,
        'driver' => $listing->driver?->active ? $listing->driver : null,
        'buyerCountry' => $buyerCountry,
        'moreFromSeller' => Listing::query()->marketplace()->forSale()->with(['seller.sellerProfile', 'driver', 'design'])
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
    $quote = $buyerCountry ? $listing->shippingCentsFor($buyerCountry, 1) : null;
    $available = $listing->availableQuantity(auth()->user());
    $tsKeys = ['Re', 'Fs', 'Qts', 'Vas', 'Sd', 'Xmax', 'SPL'];
    $factory = collect($driver?->factory_specs ?? [])->only($tsKeys)->filter(fn ($value) => filled($value));
@endphp

<x-layouts.marketing :seo="[
    'title' => $listing->title.' - '.config('app.name'),
    'description' => Str::limit(strip_tags((string) $listing->description) ?: $listing->title, 160),
    'image' => $photos[0] ?? url('/og_image.png'),
    'type' => 'product',
]">
    <x-marketplace.page :crumbs="[
        ['Marketplace', route('marketplace')],
        [$listing->category->pluralLabel(), route('marketplace', ['type' => $listing->category->value])],
        [$listing->title, null],
    ]">
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

        <x-analytics.events :events="[\App\Support\Analytics\Ga4::payload('view_item', \App\Support\Analytics\Ga4::ecommerce([\App\Support\Analytics\Ga4::item($listing)]))]" />

        <!-- Content Container -->
        <div class="space-y-8">
            <!-- Top Section: Image and Details -->
            <div class="lg:grid lg:grid-cols-2 lg:gap-8">
                <!-- Left: Image gallery -->
                <x-marketplace.gallery :images="$photos" :alt="$listing->title" class="relative mb-6 lg:mb-0" />

                <!-- Right: Listing Info -->
                <div class="space-y-6">
                    <!-- Title and Tag -->
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">{{ $listing->title }}</h1>
                        <p class="mt-2 text-lg text-gray-600 italic">{{ $listing->category->label() }} &middot; {{ $listing->condition->label() }}@if ($listing->isLot()) &middot; Sold as a {{ $lotWord }}@endif</p>
                    </div>

                    <!-- Seller Info -->
                    <div>
                        <p class="text-sm text-gray-500">Sold by</p>
                        @if ($shopUrl)
                            <a href="{{ $shopUrl }}"><p class="text-lg font-medium text-gray-900 hover:text-link-400">{{ $shopName }}</p></a>
                        @else
                            <p class="text-lg font-medium text-gray-900">{{ $shopName }}</p>
                        @endif
                    </div>

                    <!-- Buy box -->
                    <livewire:buy-box :purchasable="$listing" />

                    <!-- Specifications -->
                    <x-marketplace.specs :items="[
                        'Type' => $listing->category->label(),
                        'Condition' => $listing->condition->label(),
                        'Available' => $available.' '.($listing->isLot() ? Str::plural($lotWord, $available) : 'in stock'),
                        'Ships from' => Countries::name($listing->ships_from_country),
                        'Handling time' => $listing->handling_days.' '.Str::plural('day', $listing->handling_days),
                        'Returns' => $listing->returns_accepted ? 'Accepted' : 'No returns',
                    ]" />
                </div>
            </div>

            <!-- Built from: the design a kit or a finished speaker comes from -->
            @if ($design)
                <x-marketplace.section title="Built from this design" data-built-from>
                    <div class="max-w-xs">
                        <x-marketing.design-card-container :designs="[$design]" cols="grid-cols-1" :padded="false" />
                    </div>
                </x-marketplace.section>
            @endif

            <!-- About the driver: used gear that is a catalogue driver -->
            @if ($driver)
                <x-marketplace.section title="About this driver" data-about-driver>
                    <div class="gap-8 sm:grid sm:grid-cols-[18rem_minmax(0,1fr)]">
                        <x-marketing.driver-card-container :drivers="[$driver]" cols="grid-cols-1" :padded="false" />
                        <div class="mt-6 space-y-6 sm:mt-0">
                            <div>
                                <h3 class="text-xl font-semibold text-gray-900">{{ $driver->fullName() }}</h3>
                                @if (filled($driver->tag))
                                    <p class="mt-1 italic text-gray-600">{{ $driver->tag }}</p>
                                @endif
                            </div>
                            <x-marketplace.specs title="Key specs" class="pt-4" :items="[
                                'Power Handling' => $driver->power ? $driver->power.'W' : null,
                                'Impedance' => $driver->impedance ? $driver->impedance.'Ω' : null,
                                'Size' => $driver->size,
                                'Category' => $driver->category,
                                'New retail price' => $driver->msrp_cents ? money($driver->msrp_cents) : null,
                            ]" />
                            @if ($factory->isNotEmpty())
                                <dl class="grid grid-cols-2 gap-x-6 text-sm sm:grid-cols-3">
                                    @foreach ($factory as $key => $value)
                                        <div class="flex justify-between gap-2 border-b py-2">
                                            <dt class="font-medium text-gray-600">{{ $key }}</dt>
                                            <dd class="text-gray-900">{{ $value }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif
                            <div class="flex flex-wrap gap-2">
                                <x-button tag="a" :href="route('driver', ['id' => $driver->id])">Full specs and measurements</x-button>
                                <x-button tag="a" href="{{ route('marketplace', ['driver' => $driver->id]) }}" color="gray">Other used ones</x-button>
                            </div>
                        </div>
                    </div>
                </x-marketplace.section>
            @endif

            <!-- Description -->
            <x-marketplace.section title="Description">
                @if (filled($listing->description))
                    <x-safe-html-renderer :content="(string) $listing->description" />
                @else
                    <p class="py-8 text-center text-gray-500">The seller did not add a description.</p>
                @endif
            </x-marketplace.section>

            <!-- Shipping and returns -->
            <x-marketplace.section title="Shipping and returns" data-shipping-table>
                <dl class="space-y-1 text-gray-900">
                    <div class="flex justify-between gap-4"><dt>Ships from</dt><dd>{{ Countries::name($listing->ships_from_country) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt>Domestic ({{ $listing->ships_from_country }})</dt><dd>{{ $listing->shipping_domestic_cents > 0 ? money($listing->shipping_domestic_cents) : 'Free' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt>International</dt><dd>{{ $listing->shipping_international_cents === null ? 'Not available' : ($listing->shipping_international_cents > 0 ? money($listing->shipping_international_cents) : 'Free') }}</dd></div>
                    <div class="flex justify-between gap-4"><dt>Each additional item</dt><dd>{{ $listing->additional_item_shipping_cents > 0 ? '+ '.money($listing->additional_item_shipping_cents) : 'No extra cost' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt>Handling time</dt><dd>{{ $listing->handling_days }} {{ Str::plural('day', $listing->handling_days) }}</dd></div>
                </dl>
                @if ($buyerCountry)
                    <p class="mt-3 text-sm text-gray-700">
                        @if ($quote === null)
                            The seller does not ship to {{ Countries::name($buyerCountry) }}.
                        @else
                            To your address in {{ Countries::name($buyerCountry) }}: <strong>{{ $quote > 0 ? money($quote) : 'free shipping' }}</strong> for one item.
                        @endif
                    </p>
                @endif
                <h3 class="mt-6 text-lg font-medium text-gray-900">Returns</h3>
                <p class="mt-1 text-sm text-gray-700">
                    @if ($listing->returns_accepted)
                        {{ filled($listing->return_policy) ? $listing->return_policy : 'The seller accepts returns. Contact them to arrange it.' }}
                    @else
                        This seller does not accept returns. Ask a question before you buy.
                    @endif
                </p>
            </x-marketplace.section>

            <!-- Seller -->
            <x-marketplace.section title="Seller">
                <div class="flex flex-wrap items-center gap-4">
                    <div class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-md bg-brand-950 text-xl font-bold text-white">{{ Str::upper(Str::substr((string) $shopName, 0, 1)) }}</div>
                    <div class="min-w-0 flex-1">
                        @if ($shopUrl)<a href="{{ $shopUrl }}" class="block truncate text-lg font-medium text-gray-900 hover:text-link-400">{{ $shopName }}</a>@else<span class="block truncate text-lg font-medium text-gray-900">{{ $shopName }}</span>@endif
                        @if ($shop?->country)
                            <p class="text-sm text-gray-500">{{ Countries::name($shop->country) }}</p>
                        @endif
                    </div>
                    @unless ($isOwner)
                        <div class="flex flex-wrap gap-2">
                            <x-button tag="a" :href="auth()->check() ? url('/chatify/'.$listing->seller_id) : route('login')">
                                <x-phosphor-chat-circle class="mr-1 inline h-4 w-4" /> Contact seller
                            </x-button>
                            @if ($shopUrl)
                                <x-button tag="a" :href="$shopUrl" color="gray">Visit shop</x-button>
                            @endif
                        </div>
                    @endunless
                </div>
            </x-marketplace.section>

            @if ($moreFromSeller->isNotEmpty())
                <x-marketplace.section :title="'More from '.$shopName">
                    <x-marketing.listing-card-container :listings="$moreFromSeller" cols="grid-cols-1 sm:grid-cols-2 lg:grid-cols-4" :padded="false" />
                </x-marketplace.section>
            @endif
        </div>
    </x-marketplace.page>
</x-layouts.marketing>
