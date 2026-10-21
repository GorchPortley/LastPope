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
 *   ships_to_me (1: only listings the buyer's default address country can be shipped to), sort (newest|price_asc|price_desc).
 */
render(function (View $view) {
    $request = request();
    $user = auth()->user();

    // The country of the buyer's default address (or first address) for the "ships to me" filter.
    $myCountry = $user
        ? $user->addresses()->orderByDesc('is_default')->orderBy('id')->value('country')
        : null;

    // Only what members sell to each other; SDLabs' own new drivers (the store channel) are sold from the driver pages.
    $query = Listing::query()->marketplace()->forSale()->with(['seller.sellerProfile', 'driver']);

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

    $sort = $request->query('sort', 'newest');
    match ($sort) {
        'price_asc' => $query->orderBy('price_cents')->orderByDesc('id'),
        'price_desc' => $query->orderByDesc('price_cents')->orderByDesc('id'),
        default => $query->orderByDesc('created_at')->orderByDesc('id'),
    };

    return $view->with([
        'listings' => $query->paginate(12)->withQueryString(),
        'categories' => DriverCategoryEnum::cases(),
        'listingTypes' => ListingCategory::marketplaceCases(),
        'myCountry' => $myCountry,
        'myCountryName' => $myCountry ? Countries::name($myCountry) : null,
        'filtered' => $request->hasAny(['q', 'condition', 'type', 'category', 'driver', 'min_price', 'max_price', 'ships_to_me']),
        'driverFilter' => filled($request->query('driver')) ? Driver::find((int) $request->query('driver')) : null,
    ]);
});
?>

<x-layouts.marketing :seo="['title' => 'Marketplace - '.config('app.name'), 'description' => 'New and used speaker drivers from independent shops and builders.']">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Marketplace</h1>
                <p class="mt-1 text-gray-600 dark:text-zinc-400">New and used speaker drivers from independent shops and builders.</p>
            </div>
            <x-button tag="a" :href="auth()->check() ? route('dashboard.listings') : route('register')" color="gray">
                <x-phosphor-tag class="mr-1 inline h-4 w-4" /> Sell your gear
            </x-button>
        </div>

        <div class="mt-6 gap-6 lg:grid lg:grid-cols-[17rem_minmax(0,1fr)]" x-data="{ filters: false }">
            <button type="button" @click="filters = ! filters" class="mb-4 flex w-full items-center justify-between border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-800 lg:hidden dark:border-zinc-600 dark:bg-zinc-800 dark:text-white">
                <span class="flex items-center gap-2"><x-phosphor-funnel class="h-4 w-4" /> Filters @if ($filtered)<x-marketplace.badge color="primary">on</x-marketplace.badge>@endif</span>
                <x-phosphor-caret-right class="h-4 w-4 transition" x-bind:class="filters && 'rotate-90'" />
            </button>

            <aside :class="filters ? 'block' : 'hidden lg:block'">
                <form method="GET" action="{{ route('marketplace') }}" class="space-y-5 border border-gray-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <div>
                        <label for="f-q" class="mb-1 block text-sm font-medium text-gray-700 dark:text-zinc-300">Search</label>
                        <input id="f-q" type="search" name="q" value="{{ request('q') }}" placeholder="Title, brand, model, shop" class="w-full border-gray-300 text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white">
                    </div>

                    @if ($driverFilter)
                        <div class="flex items-center justify-between gap-2 bg-purple-50 px-3 py-2 text-sm text-purple-900 dark:bg-purple-950 dark:text-purple-200">
                            <span>Driver: {{ $driverFilter->fullName() }}</span>
                            <input type="hidden" name="driver" value="{{ $driverFilter->id }}">
                            <a href="{{ route('marketplace', request()->except(['driver', 'page'])) }}" class="font-bold" aria-label="Remove driver filter">&times;</a>
                        </div>
                    @endif

                    <div>
                        <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-zinc-300">Condition</span>
                        <div class="grid grid-cols-3 text-center text-sm">
                            @foreach (['' => 'All', 'new' => 'New', 'used' => 'Used'] as $value => $label)
                                <label class="cursor-pointer">
                                    <input type="radio" name="condition" value="{{ $value }}" class="peer sr-only" @checked((string) request('condition', '') === (string) $value)>
                                    <span class="block border border-gray-300 px-2 py-1.5 text-gray-700 peer-checked:border-purple-950 peer-checked:bg-purple-950 peer-checked:text-white peer-focus-visible:ring-2 dark:border-zinc-600 dark:text-zinc-200">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <label for="f-category" class="mb-1 block text-sm font-medium text-gray-700 dark:text-zinc-300">Driver type</label>
                        <select id="f-category" name="category" class="w-full border-gray-300 text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white">
                            <option value="">All types</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->value }}" @selected(request('category') === $category->value)>{{ $category->value }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-zinc-300">Price ({{ strtoupper(config('marketplace.currency')) }})</span>
                        <div class="flex items-center gap-2">
                            <input type="number" name="min_price" min="0" step="1" value="{{ request('min_price') }}" placeholder="Min" aria-label="Minimum price" class="w-1/2 border-gray-300 text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white">
                            <span class="text-gray-400">-</span>
                            <input type="number" name="max_price" min="0" step="1" value="{{ request('max_price') }}" placeholder="Max" aria-label="Maximum price" class="w-1/2 border-gray-300 text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white">
                        </div>
                    </div>

                    <div>
                        @if ($myCountry)
                            <label class="flex cursor-pointer items-start gap-2 text-sm text-gray-700 dark:text-zinc-300">
                                <input type="checkbox" name="ships_to_me" value="1" class="mt-0.5 border-gray-300 text-purple-700" @checked(request()->boolean('ships_to_me'))>
                                <span>Ships to {{ $myCountryName }}<span class="block text-xs text-gray-500 dark:text-zinc-400">Your default address country</span></span>
                            </label>
                        @else
                            <p class="text-xs text-gray-500 dark:text-zinc-400">
                                @auth
                                    <a class="underline" href="{{ route('settings.addresses') }}">Add an address</a> to filter by what ships to you.
                                @else
                                    <a class="underline" href="{{ route('login') }}">Log in</a> and add an address to filter by what ships to you.
                                @endauth
                            </p>
                        @endif
                    </div>

                    <div>
                        <label for="f-sort" class="mb-1 block text-sm font-medium text-gray-700 dark:text-zinc-300">Sort by</label>
                        <select id="f-sort" name="sort" class="w-full border-gray-300 text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white">
                            <option value="newest" @selected(request('sort', 'newest') === 'newest')>Newest first</option>
                            <option value="price_asc" @selected(request('sort') === 'price_asc')>Price: low to high</option>
                            <option value="price_desc" @selected(request('sort') === 'price_desc')>Price: high to low</option>
                        </select>
                    </div>

                    <div class="flex gap-2">
                        <x-button type="submit" class="flex-1">Apply</x-button>
                        <x-button tag="a" href="{{ route('marketplace') }}" color="gray" class="flex-1">Clear</x-button>
                    </div>
                </form>
            </aside>

            <section aria-label="Listings">
                <p class="mb-4 text-sm text-gray-600 dark:text-zinc-400">
                    {{ number_format($listings->total()) }} {{ \Illuminate\Support\Str::plural('listing', $listings->total()) }}@if ($filtered) match your filters @endif
                </p>

                @if ($listings->isEmpty())
                    <div class="border border-dashed border-gray-300 bg-white p-12 text-center dark:border-zinc-600 dark:bg-zinc-800">
                        <x-phosphor-storefront-duotone class="mx-auto h-12 w-12 text-gray-400" />
                        <p class="mt-3 font-medium text-gray-900 dark:text-white">Nothing here yet</p>
                        <p class="mt-1 text-sm text-gray-600 dark:text-zinc-400">Try removing a filter, or check back soon.</p>
                        @if ($filtered)
                            <x-button tag="a" href="{{ route('marketplace') }}" color="gray" class="mt-4">Clear filters</x-button>
                        @endif
                    </div>
                @else
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($listings as $listing)
                            <x-marketplace.listing-card :listing="$listing" />
                        @endforeach
                    </div>
                    <div class="mt-8 flex justify-center">
                        {{ $listings->links('theme::partials.pagination') }}
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-layouts.marketing>
