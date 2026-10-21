<?php

use App\Enums\DriverCategoryEnum;
use App\Enums\ListingCategory;
use App\Enums\ListingCondition;
use App\Models\Driver;
use App\Models\Listing;
use App\Support\Countries;
use Illuminate\Contracts\View\View;
use function Laravel\Folio\{name, render};

name('marketplace');

/*
 * Plain GET filters (bookmarkable, no JavaScript needed):
 *   q, condition (new|used), type (kit|completed_speaker|used_gear), category (driver category), driver (id), min_price / max_price (dollars),
 *   ships_to_me (1: only listings the buyer's default address country can be shipped to),
 *   sort (created_at|price) with direction (asc|desc); the older sort=newest|price_asc|price_desc still works.
 */
render(function (View $view) {
    $request = request();
    $user = auth()->user();

    // The country of the buyer's default address (or first address) for the "ships to me" filter.
    $myCountry = $user
        ? $user->addresses()->orderByDesc('is_default')->orderBy('id')->value('country')
        : null;

    // Only what members sell to each other; SDLabs' own new drivers (the store channel) are sold from the driver pages.
    $query = Listing::query()->marketplace()->forSale()->with(['seller.sellerProfile', 'driver', 'design']);

    if (filled($type = $request->query('type')) && in_array($type, ListingCategory::marketplaceValues(), true)) {
        $query->ofCategory($type);
    }

    $condition = $request->query('condition');
    if ($condition === 'new') {
        $query->brandNew();
    } elseif ($condition === 'used') {
        $query->used();
    }

    if (filled($category = $request->query('category')) && DriverCategoryEnum::tryFrom((string) $category)) {
        $query->whereHas('driver', fn ($q) => $q->where('category', $category));
    }

    if (filled($driverId = $request->query('driver'))) {
        $query->where('driver_id', (int) $driverId);
    }

    if (is_numeric($request->query('min_price'))) {
        $query->where('price_cents', '>=', (int) round($request->query('min_price') * 100));
    }
    if (is_numeric($request->query('max_price'))) {
        $query->where('price_cents', '<=', (int) round($request->query('max_price') * 100));
    }

    $shipsToMe = $myCountry !== null && $request->boolean('ships_to_me');
    if ($shipsToMe) {
        $query->where(fn ($q) => $q->where('ships_from_country', $myCountry)->orWhereNotNull('shipping_international_cents'));
    }

    if (filled($term = trim((string) $request->query('q')))) {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $query->where(function ($q) use ($like) {
            $q->where('title', 'like', $like)
                ->orWhereHas('driver', fn ($d) => $d->where('brand', 'like', $like)->orWhere('model', 'like', $like))
                ->orWhereHas('seller.sellerProfile', fn ($s) => $s->where('shop_name', 'like', $like));
        });
    }

    // Sort like the design and driver pages do: a field and a direction.
    $sort = (string) $request->query('sort', 'created_at');
    $direction = strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
    [$sort, $direction] = match ($sort) {
        'price_asc' => ['price', 'asc'],
        'price_desc' => ['price', 'desc'],
        'newest' => ['created_at', 'desc'],
        default => [$sort === 'price' ? 'price' : 'created_at', $direction],
    };
    $query->orderBy($sort === 'price' ? 'price_cents' : 'created_at', $direction)->orderByDesc('id');

    return $view->with([
        'listings' => $query->paginate(12)->withQueryString(),
        'categories' => DriverCategoryEnum::cases(),
        'listingTypes' => ListingCategory::marketplaceCases(),
        'myCountry' => $myCountry,
        'myCountryName' => $myCountry ? Countries::name($myCountry) : null,
        'filtered' => $request->hasAny(['q', 'condition', 'type', 'category', 'driver', 'min_price', 'max_price', 'ships_to_me']),
        'driverFilter' => filled($request->query('driver')) ? Driver::find((int) $request->query('driver')) : null,
        'sortField' => $sort,
        'sortDirection' => $direction,
        'activeType' => in_array($type, ListingCategory::marketplaceValues(), true) ? $type : '',
    ]);
});
?>


@php
    $tabs = ['' => ['All', route('marketplace', request()->except(['type', 'page']))]];
    foreach ($listingTypes as $listingType) {
        $tabs[$listingType->value] = [$listingType->pluralLabel(), route('marketplace', array_merge(request()->except(['type', 'page']), ['type' => $listingType->value]))];
    }
@endphp

<x-layouts.marketing :seo="['title' => 'Marketplace - '.config('app.name'), 'description' => 'Kits, finished speakers and used gear from the community.']">
    <div>
        <div class="flex h-full w-full rounded-md">
            <livewire:banner-display location="marketplace_page">
        </div>
        <div class="flex-row lg:flex w-full h-full mt-5" x-data="{ filters: false }">
            <div class="lg:w-1/5 lg:self-start">
                <button type="button" @click="filters = ! filters" class="mb-4 flex w-full items-center justify-between rounded-md bg-white px-4 py-3 text-sm font-medium text-gray-700 shadow lg:hidden" :aria-expanded="filters">
                    <span class="flex items-center gap-2"><x-phosphor-funnel class="h-4 w-4" /> Filters @if ($filtered)<x-marketplace.badge color="primary">on</x-marketplace.badge>@endif</span>
                    <x-phosphor-caret-right class="h-4 w-4 transition" x-bind:class="filters && 'rotate-90'" />
                </button>

                <div :class="filters ? 'block' : 'hidden lg:block'" class="h-full rounded-md p-4 bg-white shadow">
                    <form method="GET" action="{{ route('marketplace') }}" class="space-y-6">
                        @if ($activeType !== '')
                            <input type="hidden" name="type" value="{{ $activeType }}">
                        @endif

                        <!-- Search -->
                        <div>
                            <label for="f-q" class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                            <input id="f-q" type="search" name="q" value="{{ request('q') }}" placeholder="Title, brand, model, shop" class="w-full rounded-md border-gray-300">
                        </div>

                        @if ($driverFilter)
                            <div class="flex items-center justify-between gap-2 rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700">
                                <span>Driver: {{ $driverFilter->fullName() }}</span>
                                <input type="hidden" name="driver" value="{{ $driverFilter->id }}">
                                <a href="{{ route('marketplace', request()->except(['driver', 'page'])) }}" class="font-bold" aria-label="Remove driver filter">&times;</a>
                            </div>
                        @endif

                        <!-- Sort -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Sort By</label>
                            <div class="flex gap-2">
                                <select name="sort" aria-label="Sort by" class="w-1/2 rounded-md border-gray-300">
                                    <option value="created_at" @selected($sortField === 'created_at')>Date</option>
                                    <option value="price" @selected($sortField === 'price')>Price</option>
                                </select>
                                <select name="direction" aria-label="Sort direction" class="w-1/2 rounded-md border-gray-300">
                                    <option value="asc" @selected($sortDirection === 'asc')>Ascending</option>
                                    <option value="desc" @selected($sortDirection === 'desc')>Descending</option>
                                </select>
                            </div>
                        </div>

                        <!-- Condition Filter -->
                        <div>
                            <label for="f-condition" class="block text-sm font-medium text-gray-700 mb-2">Condition</label>
                            <select id="f-condition" name="condition" class="w-full rounded-md border-gray-300">
                                <option value="">Any condition</option>
                                <option value="new" @selected(request('condition') === 'new')>New</option>
                                <option value="used" @selected(request('condition') === 'used')>Used</option>
                            </select>
                        </div>

                        <!-- Driver category Filter -->
                        <div>
                            <label for="f-category" class="block text-sm font-medium text-gray-700 mb-2">Driver Category</label>
                            <select id="f-category" name="category" class="w-full rounded-md border-gray-300">
                                <option value="">All Categories</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->value }}" @selected(request('category') === $category->value)>{{ $category->value }}</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Price Filter -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Price Range ({{ strtoupper(config('marketplace.currency')) }})</label>
                            <div class="flex items-center gap-2">
                                <input type="number" name="min_price" min="0" step="1" value="{{ request('min_price') }}" placeholder="Min" aria-label="Minimum price" class="w-1/2 rounded-md border-gray-300">
                                <span>-</span>
                                <input type="number" name="max_price" min="0" step="1" value="{{ request('max_price') }}" placeholder="Max" aria-label="Maximum price" class="w-1/2 rounded-md border-gray-300">
                            </div>
                        </div>

                        <!-- Shipping -->
                        <div>
                            @if ($myCountry)
                                <label class="flex cursor-pointer items-start gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="ships_to_me" value="1" class="mt-0.5 rounded border-gray-300" @checked(request()->boolean('ships_to_me'))>
                                    <span>Ships to {{ $myCountryName }}<span class="block text-xs text-gray-500">Your default address country</span></span>
                                </label>
                            @else
                                <p class="text-xs text-gray-500">
                                    @auth
                                        <a class="underline" href="{{ route('settings.addresses') }}">Add an address</a> to filter by what ships to you.
                                    @else
                                        <a class="underline" href="{{ route('login') }}">Log in</a> and add an address to filter by what ships to you.
                                    @endauth
                                </p>
                            @endif
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex gap-2">
                            <x-button type="submit" class="flex-1 px-4 py-2">
                                Apply Filters
                            </x-button>
                            <a href="{{ route('marketplace') }}" class="flex-1 px-4 py-2 bg-gray-100 text-center text-gray-700 rounded-md hover:bg-gray-200">
                                Clear
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="flex flex-col h-full w-full lg:w-4/5 rounded-md lg:pl-6 mt-4 lg:mt-0">
                <div class="flex flex-wrap items-end justify-between gap-4 px-4 pb-4">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">Marketplace</h1>
                        <p class="mt-1 text-gray-600">Kits, finished speakers and used gear from the community</p>
                    </div>
                    <x-button tag="a" :href="auth()->check() ? route('dashboard.listings') : route('register')" color="gray">
                        <x-phosphor-tag class="mr-1 inline h-4 w-4" /> Sell your gear
                    </x-button>
                </div>

                <div class="px-4">
                    <x-marketplace.tabs :items="$tabs" :active="$activeType" label="Listing types" />
                    <p class="mt-3 text-sm text-gray-600" data-listing-count>
                        {{ number_format($listings->total()) }} {{ \Illuminate\Support\Str::plural('listing', $listings->total()) }}@if ($filtered) match your filters @endif
                    </p>
                </div>

                @if ($listings->isEmpty())
                    <div class="p-4">
                        <x-marketplace.empty-state heading="Nothing here yet" description="Try removing a filter, or check back soon." icon="phosphor-storefront">
                            @if ($filtered)
                                <x-button tag="a" href="{{ route('marketplace') }}" color="gray">Clear filters</x-button>
                            @endif
                        </x-marketplace.empty-state>
                    </div>
                @else
                    <x-marketing.listing-card-container :listings="$listings" />
                @endif

                <div class="flex gap-4 justify-center mt-8">
                    {{ $listings->links() }}
                </div>
            </div>
        </div>
    </div>
</x-layouts.marketing>
