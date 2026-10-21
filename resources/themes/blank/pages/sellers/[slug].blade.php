<?php

use App\Models\Design;
use App\Models\Driver;
use App\Models\Listing;
use App\Models\SellerProfile;
use Illuminate\Contracts\View\View;
use function Laravel\Folio\{name, render};

name('seller');

render(function (View $view, string $slug) {
    $profile = SellerProfile::query()->with('user')->where('slug', $slug)->firstOrFail();
    $user = $profile->user;

    abort_if($user === null, 404);

    return $view->with([
        'profile' => $profile,
        'seller' => $user,
        'listings' => Listing::query()->forSale()->with(['seller.sellerProfile'])
            ->where('seller_id', $user->getKey())->latest()->paginate(12, ['*'], 'listings')->withQueryString(),
        'designs' => Design::query()->active()->where('user_id', $user->getKey())->latest()->limit(12)->get(),
        'dataPacks' => Driver::query()->active()->where('user_id', $user->getKey())->where('data_price_cents', '>', 0)->latest()->limit(12)->get(),
    ]);
});
?>

@php
    $isOwner = auth()->check() && auth()->id() === $seller->getKey();
    $image = fn ($model) => is_array($model->card_image) ? ($model->card_image[0] ?? null) : $model->card_image;
@endphp

<x-layouts.marketing :seo="['title' => $profile->shop_name.' - '.config('app.name'), 'description' => \Illuminate\Support\Str::limit((string) ($profile->bio ?: 'Shop on '.config('app.name')), 160), 'image' => url('/og_image.png')]">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <header class="flex flex-col gap-6 border border-gray-200 bg-white p-6 sm:flex-row sm:items-center dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex h-20 w-20 flex-shrink-0 items-center justify-center bg-purple-950 text-3xl font-bold text-white">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($profile->shop_name, 0, 1)) }}</div>
            <div class="min-w-0 flex-1">
                <h1 class="text-3xl font-bold text-gray-900 dark:text-white">{{ $profile->shop_name }}</h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-zinc-400">
                    @if ($profile->country)<x-phosphor-map-pin class="mr-0.5 inline h-4 w-4 align-text-bottom" />{{ \App\Support\Countries::name($profile->country) }} &middot; @endif
                    Selling since {{ $profile->created_at->format('F Y') }}
                    @if ($profile->canReceivePayouts())&middot; <x-marketplace.badge color="success">Verified payouts</x-marketplace.badge>@endif
                </p>
                @if (filled($profile->bio))
                    <p class="mt-3 max-w-3xl whitespace-pre-line text-gray-700 dark:text-zinc-300">{{ $profile->bio }}</p>
                @endif
            </div>
            @unless ($isOwner)
                <div class="flex-shrink-0">
                    <x-button tag="a" :href="auth()->check() ? url('/chatify/'.$seller->getKey()) : route('login')" color="gray">
                        <x-phosphor-chat-circle class="mr-1 inline h-4 w-4" /> Contact seller
                    </x-button>
                </div>
            @endunless
        </header>

        <section class="mt-10" aria-labelledby="shop-listings">
            <h2 id="shop-listings" class="mb-4 text-xl font-semibold text-gray-900 dark:text-white">Listings <span class="text-base font-normal text-gray-500">({{ $listings->total() }})</span></h2>
            @if ($listings->isEmpty())
                <p class="border border-dashed border-gray-300 p-8 text-center text-gray-500 dark:border-zinc-600 dark:text-zinc-400">No listings for sale right now.</p>
            @else
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($listings as $listing)
                        <x-marketplace.listing-card :listing="$listing" />
                    @endforeach
                </div>
                <div class="mt-6 flex justify-center">{{ $listings->links('theme::partials.pagination') }}</div>
            @endif
        </section>

        @if ($designs->isNotEmpty())
            <section class="mt-12" aria-labelledby="shop-designs">
                <h2 id="shop-designs" class="mb-4 text-xl font-semibold text-gray-900 dark:text-white">Designs</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($designs as $design)
                        <a href="{{ route('design', ['id' => $design->id]) }}" class="group flex flex-col overflow-hidden border border-gray-200 bg-white shadow-sm transition hover:shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                            <div class="flex h-44 items-center justify-center bg-gray-50 p-3 dark:bg-zinc-900">
                                @if ($image($design))
                                    <img src="{{ Storage::url($image($design)) }}" alt="{{ $design->name }}" loading="lazy" class="max-h-full max-w-full object-contain">
                                @else
                                    <x-phosphor-image-duotone class="h-12 w-12 text-gray-300" />
                                @endif
                            </div>
                            <div class="p-4">
                                <h3 class="line-clamp-1 font-semibold text-gray-900 dark:text-white">{{ $design->name }}</h3>
                                <p class="line-clamp-1 text-sm text-gray-500 dark:text-zinc-400">{{ $design->category }}</p>
                                <p class="mt-2 font-bold text-gray-900 dark:text-white">{{ $design->price_cents > 0 ? money($design->price_cents) : 'Free' }}</p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($dataPacks->isNotEmpty())
            <section class="mt-12" aria-labelledby="shop-data">
                <h2 id="shop-data" class="mb-4 text-xl font-semibold text-gray-900 dark:text-white">Driver data packs</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($dataPacks as $driver)
                        <a href="{{ route('driver', ['id' => $driver->id]) }}" class="group flex flex-col overflow-hidden border border-gray-200 bg-white shadow-sm transition hover:shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                            <div class="flex h-44 items-center justify-center bg-gray-50 p-3 dark:bg-zinc-900">
                                @if ($image($driver))
                                    <img src="{{ Storage::url($image($driver)) }}" alt="{{ $driver->fullName() }}" loading="lazy" class="max-h-full max-w-full object-contain">
                                @else
                                    <x-phosphor-image-duotone class="h-12 w-12 text-gray-300" />
                                @endif
                            </div>
                            <div class="p-4">
                                <h3 class="line-clamp-1 font-semibold text-gray-900 dark:text-white">{{ $driver->fullName() }}</h3>
                                <p class="line-clamp-1 text-sm text-gray-500 dark:text-zinc-400">Measurement data pack</p>
                                <p class="mt-2 font-bold text-gray-900 dark:text-white">{{ money($driver->data_price_cents) }}</p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-layouts.marketing>
