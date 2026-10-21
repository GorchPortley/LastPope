<?php

use App\Models\Design;
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
        'listings' => Listing::query()->marketplace()->forSale()->with(['seller.sellerProfile', 'driver', 'design'])
            ->where('seller_id', $user->getKey())->latest()->paginate(12, ['*'], 'listings')->withQueryString(),
        'designs' => Design::query()->active()->with('designer', 'reviews')->where('user_id', $user->getKey())->latest()->limit(12)->get(),
    ]);
});
?>

@php
    $isOwner = auth()->check() && auth()->id() === $seller->getKey();
@endphp

<x-layouts.marketing :seo="['title' => $profile->shop_name.' - '.config('app.name'), 'description' => \Illuminate\Support\Str::limit((string) ($profile->bio ?: 'Shop on '.config('app.name')), 160), 'image' => url('/og_image.png')]">
    <x-marketplace.page :crumbs="[['Marketplace', route('marketplace')], [$profile->shop_name, null]]">
        <div class="space-y-8">
            <!-- Shop header -->
            <div class="flex flex-col gap-6 sm:flex-row sm:items-center">
                <div class="flex h-20 w-20 flex-shrink-0 items-center justify-center rounded-lg bg-brand-950 text-3xl font-bold text-white">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($profile->shop_name, 0, 1)) }}</div>
                <div class="min-w-0 flex-1">
                    <h1 class="text-3xl font-bold text-gray-900">{{ $profile->shop_name }}</h1>
                    <p class="mt-2 text-lg text-gray-600 italic">
                        @if ($profile->country)<x-phosphor-map-pin class="mr-0.5 inline h-4 w-4 align-text-bottom" />{{ \App\Support\Countries::name($profile->country) }} &middot; @endif
                        Selling since {{ $profile->created_at->format('F Y') }}
                    </p>
                    @if ($profile->canReceivePayouts())
                        <p class="mt-2"><x-marketplace.badge color="success">Verified payouts</x-marketplace.badge></p>
                    @endif
                    @if (filled($profile->bio))
                        <p class="mt-3 max-w-3xl whitespace-pre-line text-gray-700">{{ $profile->bio }}</p>
                    @endif
                </div>
                @unless ($isOwner)
                    <div class="flex-shrink-0">
                        <x-button tag="a" :href="auth()->check() ? url('/chatify/'.$seller->getKey()) : route('login')">
                            <x-phosphor-chat-circle class="mr-1 inline h-4 w-4" /> Contact seller
                        </x-button>
                    </div>
                @endunless
            </div>

            <!-- Listings -->
            <x-marketplace.section title="Listings" :count="$listings->total()" id="shop-listings">
                @if ($listings->isEmpty())
                    <x-marketplace.empty-state heading="No listings for sale right now" description="Check back later, or have a look at the designs below." icon="phosphor-tag" :bordered="false" />
                @else
                    <x-marketing.listing-card-container :listings="$listings" cols="grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" :padded="false" />
                    <div class="flex gap-4 justify-center mt-8">{{ $listings->links() }}</div>
                @endif
            </x-marketplace.section>

            <!-- Designs -->
            @if ($designs->isNotEmpty())
                <x-marketplace.section title="Designs" :count="$designs->count()" id="shop-designs">
                    <x-marketing.design-card-container :designs="$designs" cols="grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" :padded="false" />
                </x-marketplace.section>
            @endif
        </div>
    </x-marketplace.page>
</x-layouts.marketing>
