<?php

use App\Models\Design;
use App\Models\Driver;
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
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <h1 class="mb-6 text-3xl font-bold text-gray-900 dark:text-white">
            Shopping cart
            @if (! $summary->isEmpty())
                <span class="text-lg font-normal text-gray-500 dark:text-zinc-400">({{ $summary->itemCount }} {{ \Illuminate\Support\Str::plural('item', $summary->itemCount) }})</span>
            @endif
        </h1>

        @if ($notice)
            <x-marketplace.notice :level="$noticeLevel" class="mb-6" role="status">{{ $notice }}</x-marketplace.notice>
        @endif

        @if (! $summary->isEmpty())
            <div class="gap-8 lg:grid lg:grid-cols-[minmax(0,1fr)_22rem]">
                <div class="space-y-6">
                    @foreach ($summary->sellers as $group)
                        <section class="border border-gray-200 bg-white dark:border-zinc-700 dark:bg-zinc-800" wire:key="group-{{ $group->seller?->getKey() ?? 0 }}">
                            <header class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 px-4 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                                <span class="text-gray-600 dark:text-zinc-400">
                                    Sold by
                                    @if ($group->seller?->sellerProfile)
                                        <a href="{{ route('seller', ['slug' => $group->seller->sellerProfile->slug]) }}" class="font-semibold text-gray-900 hover:underline dark:text-white">{{ $group->displayName }}</a>
                                    @else
                                        <span class="font-semibold text-gray-900 dark:text-white">{{ $group->displayName }}</span>
                                    @endif
                                </span>
                                <span class="text-gray-500 dark:text-zinc-400">{{ $group->hasPhysicalItems() ? 'Ships separately from this seller' : 'Digital delivery' }}</span>
                            </header>

                            <ul class="divide-y divide-gray-200 dark:divide-zinc-700">
                                @foreach ($group->lines as $line)
                                    <li class="flex gap-4 p-4" wire:key="line-{{ $line->id() }}">
                                        <x-marketplace.thumb :purchasable="$line->purchasable" />

                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                                                <h2 class="min-w-0 font-semibold text-gray-900 dark:text-white">
                                                    @if ($line->purchasable instanceof Design)
                                                        <a href="{{ route('design', ['id' => $line->purchasable->getKey()]) }}" class="hover:underline">{{ $line->title }}</a>
                                                    @elseif ($line->purchasable instanceof Driver)
                                                        <a href="{{ route('driver', ['id' => $line->purchasable->getKey()]) }}" class="hover:underline">{{ $line->title }}</a>
                                                    @elseif ($line->purchasable instanceof Listing)
                                                        <a href="{{ route('listing', ['uuid' => $line->purchasable->uuid]) }}" class="hover:underline">{{ $line->title }}</a>
                                                    @else
                                                        {{ $line->title }}
                                                    @endif
                                                </h2>
                                                <p class="font-semibold text-gray-900 dark:text-white">{{ money($line->lineTotalCents()) }}</p>
                                            </div>

                                            <p class="mt-0.5 text-sm text-gray-500 dark:text-zinc-400">
                                                {{ $line->isDigital ? 'Digital download' : 'Physical item, shipped by the seller' }}
                                                @if (! $line->isDigital && $line->purchasable instanceof Listing)
                                                    &middot; {{ $line->purchasable->condition->label() }}
                                                @endif
                                                &middot; {{ money($line->unitPriceCents) }} each
                                            </p>

                                            @if ($line->priceChanged())
                                                <p class="mt-1 text-sm font-medium text-amber-700 dark:text-amber-400">
                                                    The price changed from {{ money($line->storedUnitPriceCents) }} to {{ money($line->unitPriceCents) }} since you added it.
                                                </p>
                                            @endif
                                            @foreach ($line->issues as $issue)
                                                <p class="mt-1 text-sm font-medium text-red-600 dark:text-red-400">{{ $issue->message }}</p>
                                            @endforeach

                                            <div class="mt-3 flex flex-wrap items-center gap-4">
                                                @if (! $line->isDigital && $line->purchasable)
                                                    <div class="inline-flex items-center border border-gray-300 dark:border-zinc-600">
                                                        <button type="button" wire:click="updateQuantity({{ $line->id() }}, {{ $line->quantity - 1 }})" class="px-2.5 py-1.5 text-gray-700 hover:bg-gray-100 dark:text-zinc-200 dark:hover:bg-zinc-700" aria-label="Decrease quantity">&minus;</button>
                                                        <input type="number" min="1" @if($line->maxQuantity) max="{{ $line->maxQuantity }}" @endif
                                                               value="{{ $line->quantity }}"
                                                               wire:change="updateQuantity({{ $line->id() }}, $event.target.value)"
                                                               aria-label="Quantity"
                                                               class="w-14 appearance-none border-0 border-x border-gray-300 bg-transparent px-1 py-1.5 text-center text-sm focus:ring-0 dark:border-zinc-600 dark:text-white">
                                                        <button type="button" wire:click="updateQuantity({{ $line->id() }}, {{ $line->quantity + 1 }})" class="px-2.5 py-1.5 text-gray-700 hover:bg-gray-100 dark:text-zinc-200 dark:hover:bg-zinc-700" aria-label="Increase quantity">+</button>
                                                    </div>
                                                @endif
                                                <button type="button" wire:click="removeItem({{ $line->id() }})" class="text-sm font-medium text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300">
                                                    Remove
                                                </button>
                                            </div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>

                            <footer class="flex justify-between border-t border-gray-200 bg-gray-50 px-4 py-2 text-sm text-gray-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
                                <span>Subtotal from this seller</span>
                                <span class="font-medium text-gray-900 dark:text-white">{{ money($group->subtotalCents) }}</span>
                            </footer>
                        </section>
                    @endforeach
                </div>

                <aside class="mt-8 lg:mt-0">
                    <div class="border border-gray-200 bg-white p-5 lg:sticky lg:top-6 dark:border-zinc-700 dark:bg-zinc-800">
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Order summary</h2>
                        <dl class="mt-4 space-y-2 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-600 dark:text-zinc-400">Subtotal</dt>
                                <dd class="font-medium text-gray-900 dark:text-white">{{ money($summary->subtotalCents) }}</dd>
                            </div>
                            @if ($summary->needsAddress)
                                <div class="flex justify-between">
                                    <dt class="text-gray-600 dark:text-zinc-400">Shipping</dt>
                                    <dd class="text-gray-600 dark:text-zinc-400">at checkout</dd>
                                </div>
                            @endif
                        </dl>
                        @if ($summary->needsAddress)
                            <p class="mt-3 text-sm text-gray-500 dark:text-zinc-400">Shipping is calculated from your address in the next step.</p>
                        @endif

                        @if ($summary->hasIssues())
                            <p class="mt-4 text-sm font-medium text-red-600 dark:text-red-400">Fix the items marked above to continue.</p>
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
                        <a href="{{ route('marketplace') }}" class="mt-4 block text-center text-sm text-purple-700 underline hover:text-purple-900 dark:text-purple-300">Continue shopping</a>
                    </div>
                </aside>
            </div>
        @else
            <div class="border border-dashed border-gray-300 bg-white py-16 text-center dark:border-zinc-600 dark:bg-zinc-800">
                <x-phosphor-shopping-cart-duotone class="mx-auto h-14 w-14 text-gray-400" />
                <p class="mt-3 text-lg font-medium text-gray-900 dark:text-white">Your cart is empty</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-zinc-400">Designs, driver data and gear you add will wait for you here.</p>
                <div class="mt-5 flex flex-wrap justify-center gap-3">
                    <x-button tag="a" href="{{ route('marketplace') }}">Browse the marketplace</x-button>
                    <x-button tag="a" href="{{ route('designs') }}" color="gray">Browse designs</x-button>
                </div>
            </div>
        @endif
    </div>
    @endvolt
</x-layouts.marketing>
