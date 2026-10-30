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
        'designs' => Design::query()->active()->with(['designer.roles', 'designer.sellerProfile'])->withAvg('reviews', 'rating')->withCount(['reviews', 'components'])
            ->where('user_id', $user->getKey())->latest()->limit(12)->get(),
    ]);
});
?>

@php
    $isOwner = auth()->check() && auth()->id() === $seller->getKey();
@endphp

<x-layouts.marketing :seo="['title' => $profile->shop_name.' - '.config('app.name'), 'description' => \Illuminate\Support\Str::limit((string) ($profile->bio ?: 'Shop on '.config('app.name')), 160), 'image' => url('/og_image.png')]">
    <x-marketplace.page :crumbs="[['Marketplace', route('marketplace')], [$profile->shop_name, null]]">
        <div class="space-y-10">
            <!-- Shop header sheet -->
            <header class="sd-sheet flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:p-6">
                <div class="flex h-20 w-20 flex-shrink-0 items-center justify-center bg-ink font-condensed text-4xl font-bold uppercase text-white" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($profile->shop_name, 0, 1)) }}</div>
                <div class="min-w-0 flex-1">
                    <p class="sd-label">Shop</p>
                    <h1 class="sd-title mt-1 text-3xl sm:text-4xl">{{ $profile->shop_name }}</h1>
                    <p class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 font-mono text-[13px] text-ink-2">
                        @if ($profile->country)<span><x-phosphor-map-pin class="mr-0.5 inline h-4 w-4 align-text-bottom" />{{ \App\Support\Countries::name($profile->country) }}</span>@endif
                        <span>Selling since {{ $profile->created_at->format('F Y') }}</span>
                        @if ($profile->canReceivePayouts())
                            <x-marketplace.badge color="success">Verified payouts</x-marketplace.badge>
                        @endif
                    </p>
                    @if (filled($profile->bio))
                        <p class="mt-3 max-w-3xl whitespace-pre-line text-base text-ink-2">{{ $profile->bio }}</p>
                    @endif
                </div>
                @unless ($isOwner)
                    <div class="flex-shrink-0">
                        <a href="{{ auth()->check() ? url('/chatify/'.$seller->getKey()) : route('login') }}" class="sd-btn sd-btn-go">
                            <x-phosphor-chat-circle class="h-4 w-4" /> Contact seller
                        </a>
                    </div>
                @endunless
            </header>

            <!-- Listings -->
            <section id="shop-listings" aria-labelledby="shop-listings-title">
                <div class="sd-section-head mb-4">
                    <h2 id="shop-listings-title" class="sd-title text-3xl">Listings <span class="font-mono text-sm font-normal normal-case tracking-normal text-ink-2">({{ $listings->total() }})</span></h2>
                </div>
                @if ($listings->isEmpty())
                    <x-marketplace.empty-state heading="No listings for sale right now" description="Check back later, or have a look at the designs below." icon="phosphor-tag" />
                @else
                    <x-marketing.listing-card-container :listings="$listings" :padded="false" />
                    <div class="mt-8 flex justify-center gap-4">{{ $listings->links() }}</div>
                @endif
            </section>

            <!-- Designs -->
            @if ($designs->isNotEmpty())
                <section id="shop-designs" aria-labelledby="shop-designs-title">
                    <div class="sd-section-head mb-4">
                        <h2 id="shop-designs-title" class="sd-title text-3xl">Designs <span class="font-mono text-sm font-normal normal-case tracking-normal text-ink-2">({{ $designs->count() }})</span></h2>
                    </div>
                    <x-marketing.design-card-container :designs="$designs" :padded="false" />
                </section>
            @endif
        </div>
    </x-marketplace.page>
</x-layouts.marketing>
