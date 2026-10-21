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
                                    <span class="text-gray-500">{{ $group->hasPhysicalItems() ? 'Ships separately from this seller' : 'Digital delivery' }}</span>
                                </header>

                                <ul class="divide-y divide-gray-200">
                                    @foreach ($group->lines as $line)
                                        <li class="flex gap-4 p-4" wire:key="line-{{ $line->id() }}">
                                            <x-marketplace.thumb :purchasable="$line->purchasable" class="rounded-md" />

                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                                                    <h2 class="min-w-0 text-lg font-semibold text-gray-900">
                                                        @if ($line->purchasable instanceof Design)
                                                            <a href="{{ route('design', ['id' => $line->purchasable->getKey()]) }}" class="hover:text-link-400">{{ $line->title }}</a>
                                                        @elseif ($line->purchasable instanceof Listing)
                                                            <a href="{{ $line->purchasable->isStore() && $line->purchasable->driver_id ? route('driver', ['id' => $line->purchasable->driver_id]) : route('listing', ['uuid' => $line->purchasable->uuid]) }}" class="hover:text-link-400">{{ $line->title }}</a>
                                                        @else
                                                            {{ $line->title }}
                                                        @endif
                                                    </h2>
                                                    <p class="text-lg font-semibold text-gray-900">{{ money($line->lineTotalCents()) }}</p>
                                                </div>

                                                <p class="mt-0.5 text-sm text-gray-500">
                                                    {{ $line->isDigital ? 'Digital download' : 'Physical item, shipped by the seller' }}
                                                    @if (! $line->isDigital && $line->purchasable instanceof Listing)
                                                        &middot; {{ $line->purchasable->condition->label() }}
                                                    @endif
                                                    &middot; {{ money($line->unitPriceCents) }} each
                                                </p>

                                                @if ($line->priceChanged())
                                                    <p class="mt-1 text-sm font-medium text-amber-700">
                                                        The price changed from {{ money($line->storedUnitPriceCents) }} to {{ money($line->unitPriceCents) }} since you added it.
                                                    </p>
                                                @endif
                                                @foreach ($line->issues as $issue)
                                                    <p class="mt-1 text-sm font-medium text-red-600">{{ $issue->message }}</p>
                                                @endforeach

                                                <div class="mt-3 flex flex-wrap items-center gap-4">
                                                    @if (! $line->isDigital && $line->purchasable)
                                                        <div class="inline-flex items-center overflow-hidden rounded-md border border-gray-300">
                                                            <button type="button" wire:click="updateQuantity({{ $line->id() }}, {{ $line->quantity - 1 }})" class="px-2.5 py-1.5 text-gray-700 hover:bg-gray-100" aria-label="Decrease quantity">&minus;</button>
                                                            <input type="number" min="1" @if($line->maxQuantity) max="{{ $line->maxQuantity }}" @endif
                                                                   value="{{ $line->quantity }}"
                                                                   wire:change="updateQuantity({{ $line->id() }}, $event.target.value)"
                                                                   aria-label="Quantity"
                                                                   class="w-14 appearance-none border-0 border-x border-gray-300 bg-transparent px-1 py-1.5 text-center text-sm focus:ring-0">
                                                            <button type="button" wire:click="updateQuantity({{ $line->id() }}, {{ $line->quantity + 1 }})" class="px-2.5 py-1.5 text-gray-700 hover:bg-gray-100" aria-label="Increase quantity">+</button>
                                                        </div>
                                                    @endif
                                                    <button type="button" wire:click="removeItem({{ $line->id() }})" class="text-sm font-medium text-red-600 hover:text-red-800">
                                                        Remove
                                                    </button>
                                                </div>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>

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
