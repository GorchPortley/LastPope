<?php

use App\Models\Design;
use App\Models\Listing;
use App\Services\Marketplace\CartService;
use function Laravel\Folio\{middleware, name};
use Livewire\Volt\Component;

middleware('auth');
name('cart');

new class extends Component
{
    public ?string $notice = null;

    public string $noticeLevel = 'info';

    public function removeItem(int $cartItemId): void
    {
        $this->announce(app(CartService::class)->remove(auth()->user(), $cartItemId));
    }

    public function updateQuantity(int $cartItemId, $quantity): void
    {
        $this->announce(app(CartService::class)->updateQuantity(auth()->user(), $cartItemId, (int) $quantity));
    }

    private function announce($result): void
    {
        $this->notice = $result->message();
        $this->noticeLevel = $result->level();
        $this->dispatch('cart-updated');
    }

    public function with(): array
    {
        return [
            'summary' => app(CartService::class)->summary(auth()->user()),
        ];
    }
}; ?>

<x-layouts.marketing :seo="['title' => 'Your cart - '.config('app.name')]">
    @volt('cart')
    <x-marketplace.page :crumbs="[['Marketplace', route('marketplace')], ['Cart', null]]">
        <div class="space-y-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Shopping cart</h1>
                @if (! $summary->isEmpty())
                    <p class="mt-2 text-lg text-gray-600 italic">{{ $summary->itemCount }} {{ \Illuminate\Support\Str::plural('item', $summary->itemCount) }} from {{ count($summary->sellers) }} {{ \Illuminate\Support\Str::plural('seller', count($summary->sellers)) }}</p>
                @endif
            </div>

            @if ($notice)
                <x-marketplace.notice :level="$noticeLevel" role="status">{{ $notice }}</x-marketplace.notice>
            @endif

            @if (! $summary->isEmpty())
                <div class="gap-8 lg:grid lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div class="space-y-6">
                        @foreach ($summary->sellers as $group)
                            <section class="overflow-hidden rounded-lg border-2 border-gray-100 bg-white shadow-sm" wire:key="group-{{ $group->seller?->getKey() ?? 0 }}">
                                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 px-4 py-3 text-sm">
                                    <span class="text-gray-600">
                                        Sold by
                                        @if ($group->seller?->sellerProfile)
                                            <a href="{{ route('seller', ['slug' => $group->seller->sellerProfile->slug]) }}" class="font-semibold text-gray-900 hover:text-link-400">{{ $group->displayName }}</a>
                                        @else
                                            <span class="font-semibold text-gray-900">{{ $group->displayName }}</span>
                                        @endif
                                    </span>
                                    <span class="text-gray-500">
                                        @if (! $group->hasPhysicalItems())
                                            Digital delivery
                                        @elseif ($group->hasMultipleParcels())
                                            Ships in {{ count($group->shippingGroups) }} parcels
                                        @endif
                                    </span>
                                </header>

                                @php
                                    // The parcels of this seller (own stock / each supplier), then the lines that ship nowhere (downloads).
                                    $parcels = $group->shippingGroups;
                                    $parceled = collect($parcels)->flatMap(fn ($parcel) => collect($parcel->lines)->map(fn ($line) => $line->id()))->all();
                                    $others = collect($group->lines)->reject(fn ($line) => in_array($line->id(), $parceled, true))->values();
                                @endphp

                                @foreach ($parcels as $parcel)
                                    <div wire:key="parcel-{{ $group->seller?->getKey() ?? 0 }}-{{ $parcel->key }}" class="border-t border-gray-200 first:border-t-0">
                                        <x-marketplace.parcel-heading :group="$parcel" :note="$parcel->shippingPending ? 'Shipping at checkout' : ($parcel->shippingCents > 0 ? 'Shipping '.money($parcel->shippingCents) : 'Free shipping')" class="border-b border-gray-100 bg-white px-4 py-2.5" />
                                        <ul class="divide-y divide-gray-200">
                                            @foreach ($parcel->lines as $line)
                                                <x-marketplace.cart-line :line="$line" />
                                            @endforeach
                                        </ul>
                                    </div>
                                @endforeach

                                @if ($others->isNotEmpty())
                                    <div class="border-t border-gray-200 first:border-t-0">
                                        @if ($parcels !== [])
                                            <div class="flex items-center gap-2 border-b border-gray-100 bg-white px-4 py-2.5 text-sm font-medium text-gray-800">
                                                <x-phosphor-download-simple class="h-4 w-4 text-gray-500" /> Digital delivery
                                            </div>
                                        @endif
                                        <ul class="divide-y divide-gray-200">
                                            @foreach ($others as $line)
                                                <x-marketplace.cart-line :line="$line" />
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif

                                <footer class="flex justify-between border-t border-gray-200 bg-gray-50 px-4 py-2 text-sm text-gray-600">
                                    <span>Subtotal from this seller</span>
                                    <span class="font-medium text-gray-900">{{ money($group->subtotalCents) }}</span>
                                </footer>
                            </section>
                        @endforeach
                    </div>

                    <aside class="mt-8 lg:mt-0">
                        <x-marketplace.box class="lg:sticky lg:top-6">
                            <h2 class="text-xl font-semibold text-gray-900">Order summary</h2>
                            <dl class="mt-4 space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <dt class="text-gray-600">Subtotal</dt>
                                    <dd class="font-medium text-gray-900">{{ money($summary->subtotalCents) }}</dd>
                                </div>
                                @if ($summary->needsAddress)
                                    <div class="flex justify-between">
                                        <dt class="text-gray-600">Shipping</dt>
                                        <dd class="text-gray-600">at checkout</dd>
                                    </div>
                                @endif
                            </dl>
                            @if ($summary->needsAddress)
                                <p class="mt-3 text-sm text-gray-500">Shipping is calculated from your address in the next step.</p>
                            @endif

                            @if ($summary->hasIssues())
                                <p class="mt-4 text-sm font-medium text-red-600">Fix the items marked above to continue.</p>
                            @elseif ($summary->needsAddress)
                                <x-button tag="a" href="{{ route('checkout') }}" class="mt-5 w-full">
                                    Continue to shipping
                                </x-button>
                            @else
                                <form method="POST" action="{{ route('checkout.start') }}" class="mt-5">
                                    @csrf
                                    <input type="hidden" name="expected_total_cents" value="{{ $summary->totalCents() }}">
                                    <x-button type="submit" class="w-full">
                                        Checkout
                                    </x-button>
                                </form>
                            @endif
                            <a href="{{ route('marketplace') }}" class="mt-4 block text-center text-sm text-gray-500 hover:text-gray-700">Continue shopping</a>
                        </x-marketplace.box>
                    </aside>
                </div>
            @else
                <x-marketplace.empty-state heading="Your cart is empty" description="Designs and gear you add will wait for you here." icon="phosphor-shopping-cart">
                    <x-button tag="a" href="{{ route('marketplace') }}">Browse the marketplace</x-button>
                    <x-button tag="a" href="{{ route('designs') }}" color="gray">Browse designs</x-button>
                </x-marketplace.empty-state>
            @endif
        </div>
    </x-marketplace.page>
    @endvolt
</x-layouts.marketing>
