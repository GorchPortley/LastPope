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
    <div class="min-h-[60vh] bg-paper">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="pt-4">
                <livewire:banner-display location="marketplace_page">
            </div>

            <div class="grid gap-7 pb-12 pt-6 lg:grid-cols-[230px_minmax(0,1fr)]" x-data="{ filters: false }">
                <aside class="lg:self-start">
                    <button type="button" @click="filters = ! filters" class="sd-btn mb-4 w-full justify-between lg:hidden" :aria-expanded="filters">
                        <span>Filters @if ($filtered)<x-marketplace.badge color="primary">on</x-marketplace.badge>@endif</span>
                        <span x-text="filters ? '-' : '+'" aria-hidden="true"></span>
                    </button>

                    <form id="market-filters" method="GET" action="{{ route('marketplace') }}" :class="filters ? 'grid' : 'hidden lg:grid'" class="gap-5">
                        @if ($activeType !== '')
                            <input type="hidden" name="type" value="{{ $activeType }}">
                        @endif

                        <!-- Search -->
                        <div>
                            <label for="f-q" class="sd-label mb-2 block w-full border-b border-rule pb-1.5 text-ink">Search</label>
                            <input id="f-q" type="search" name="q" value="{{ request('q') }}" placeholder="Title, brand, model, shop" class="w-full rounded-none border-rule bg-white py-1.5 text-sm focus:border-ink focus:ring-0">
                        </div>

                        @if ($driverFilter)
                            <div class="flex items-center justify-between gap-2 border border-rule bg-white px-3 py-2 text-sm text-ink">
                                <span>Driver: {{ $driverFilter->fullName() }}</span>
                                <input type="hidden" name="driver" value="{{ $driverFilter->id }}">
                                <a href="{{ route('marketplace', request()->except(['driver', 'page'])) }}" class="font-bold" aria-label="Remove driver filter">&times;</a>
                            </div>
                        @endif

                        <!-- Condition Filter -->
                        <fieldset>
                            <legend class="sd-label mb-2 w-full border-b border-rule pb-1.5 text-ink">Condition</legend>
                            @foreach (['' => 'Any condition', 'new' => 'New', 'used' => 'Used'] as $value => $label)
                                <label class="flex cursor-pointer items-center gap-2 py-1 text-[15px] text-ink">
                                    <input type="radio" name="condition" value="{{ $value }}" class="h-4 w-4 border-rule text-brand-800 focus:ring-brand-800" @checked((string) request('condition') === (string) $value || ($value === '' && ! in_array(request('condition'), ['new', 'used'], true)))>
                                    {{ $label }}
                                </label>
                            @endforeach
                        </fieldset>

                        <!-- Driver category Filter -->
                        <div>
                            <label for="f-category" class="sd-label mb-2 block w-full border-b border-rule pb-1.5 text-ink">Driver category</label>
                            <select id="f-category" name="category" class="w-full rounded-none border-rule bg-white py-1.5 text-sm focus:border-ink focus:ring-0">
                                <option value="">All Categories</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->value }}" @selected(request('category') === $category->value)>{{ $category->value }}</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Price Filter -->
                        <fieldset>
                            <legend class="sd-label mb-2 w-full border-b border-rule pb-1.5 text-ink">Price range ({{ strtoupper(config('marketplace.currency')) }})</legend>
                            <div class="flex items-center gap-2">
                                <input type="number" name="min_price" min="0" step="1" value="{{ request('min_price') }}" placeholder="Min" aria-label="Minimum price" class="w-1/2 rounded-none border-rule bg-white py-1.5 font-mono text-sm focus:border-ink focus:ring-0">
                                <span class="text-ink-3">-</span>
                                <input type="number" name="max_price" min="0" step="1" value="{{ request('max_price') }}" placeholder="Max" aria-label="Maximum price" class="w-1/2 rounded-none border-rule bg-white py-1.5 font-mono text-sm focus:border-ink focus:ring-0">
                            </div>
                        </fieldset>

                        <!-- Shipping -->
                        <div>
                            @if ($myCountry)
                                <label class="flex cursor-pointer items-start gap-2 text-[15px] text-ink">
                                    <input type="checkbox" name="ships_to_me" value="1" class="mt-1 h-4 w-4 rounded-none border-rule text-brand-800 focus:ring-brand-800" @checked(request()->boolean('ships_to_me'))>
                                    <span>Ships to {{ $myCountryName }}<span class="block text-xs text-ink-3">Your default address country</span></span>
                                </label>
                            @else
                                <p class="text-xs text-ink-2">
                                    @auth
                                        <a class="text-brand-800 underline" href="{{ route('settings.addresses') }}">Add an address</a> to filter by what ships to you.
                                    @else
                                        <a class="text-brand-800 underline" href="{{ route('login') }}">Log in</a> and add an address to filter by what ships to you.
                                    @endauth
                                </p>
                            @endif
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex gap-2">
                            <button type="submit" class="sd-btn sd-btn-go sd-btn-sm flex-1">Apply filters</button>
                            <a href="{{ route('marketplace') }}" class="sd-btn sd-btn-sm flex-1">Clear</a>
                        </div>
                    </form>
                </aside>

                <section class="min-w-0">
                    <!-- Top bar: heading, sell button, sort -->
                    <div class="mb-4 flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
                        <div>
                            <h1 class="sd-title text-3xl sm:text-4xl">Marketplace</h1>
                            <p class="mt-1.5 text-ink-2">Kits, finished speakers and used gear from the community</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <label class="sd-label" for="market-sort">Sort</label>
                            <select id="market-sort" name="sort" form="market-filters" onchange="this.form.submit()" class="rounded-none border-rule bg-white py-1.5 text-sm focus:border-ink focus:ring-0">
                                <option value="created_at" @selected($sortField === 'created_at')>Date</option>
                                <option value="price" @selected($sortField === 'price')>Price</option>
                            </select>
                            <select name="direction" form="market-filters" aria-label="Sort direction" onchange="this.form.submit()" class="rounded-none border-rule bg-white py-1.5 text-sm focus:border-ink focus:ring-0">
                                <option value="asc" @selected($sortDirection === 'asc')>Ascending</option>
                                <option value="desc" @selected($sortDirection === 'desc')>Descending</option>
                            </select>
                            <a href="{{ auth()->check() ? route('dashboard.listings') : route('register') }}" class="sd-btn sd-btn-sm ml-1">Sell your gear</a>
                        </div>
                    </div>

                    <x-marketplace.tabs :items="$tabs" :active="$activeType" label="Listing types" />
                    <p class="mb-4 mt-3 font-mono text-[13px] text-ink-2" data-listing-count>
                        {{ number_format($listings->total()) }} {{ \Illuminate\Support\Str::plural('listing', $listings->total()) }}@if ($filtered) match your filters @endif
                    </p>

                    @if ($listings->isEmpty())
                        <x-marketplace.empty-state heading="Nothing here yet" description="Try removing a filter, or check back soon." icon="phosphor-storefront">
                            @if ($filtered)
                                <a href="{{ route('marketplace') }}" class="sd-btn">Clear filters</a>
                            @endif
                        </x-marketplace.empty-state>
                    @else
                        @if (filled($term = trim((string) request()->query('q'))))
                            <x-analytics.events :events="[\App\Support\Analytics\Ga4::payload('search', ['search_term' => $term])]" />
                        @endif
                        <x-marketing.listing-card-container :listings="$listings" :padded="false" />
                    @endif

                    <div class="mt-8 flex justify-center gap-4">
                        {{ $listings->links() }}
                    </div>
                </section>
            </div>
        </div>
    </div>
</x-layouts.marketing>
