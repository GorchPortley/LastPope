<?php

use App\Models\Design;
use App\Models\Driver;
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

<x-layouts.marketing>
    @volt('cart')
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <h1 class="text-2xl font-bold mb-8 dark:text-white">Shopping Cart</h1>

        @if (session()->has('error'))
            <div class="mb-4 bg-red-50 dark:bg-red-900/50 text-red-600 dark:text-red-400 p-4 rounded">
                {{ session('error') }}
                @foreach ((array) session('checkout_issues', []) as $issueMessage)
                    <div class="text-sm">{{ $issueMessage }}</div>
                @endforeach
            </div>
        @endif

        @if ($notice)
            <div @class([
                'mb-4 p-4 rounded',
                'bg-green-50 text-green-700 dark:bg-green-900/50 dark:text-green-300' => $noticeLevel === 'success',
                'bg-blue-50 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300' => $noticeLevel === 'info',
                'bg-red-50 text-red-600 dark:bg-red-900/50 dark:text-red-400' => $noticeLevel === 'error',
            ])>{{ $notice }}</div>
        @endif

        @if (! $summary->isEmpty())
            <div class="bg-white dark:bg-zinc-800 rounded-lg shadow overflow-hidden">
                @foreach ($summary->sellers as $group)
                    <div class="px-6 py-3 bg-gray-50 dark:bg-zinc-900 text-sm font-medium text-gray-600 dark:text-zinc-400">
                        Sold by {{ $group->displayName }}
                    </div>
                    <div class="divide-y divide-gray-200 dark:divide-zinc-700">
                        @foreach ($group->lines as $line)
                            <div class="p-6 flex items-center justify-between gap-4" wire:key="line-{{ $line->id() }}">
                                <div>
                                    <h3 class="font-medium dark:text-white">
                                        @if ($line->purchasable instanceof Design)
                                            <a href="{{ route('design', ['id' => $line->purchasable->getKey()]) }}" class="hover:underline">{{ $line->title }}</a>
                                        @elseif ($line->purchasable instanceof Driver)
                                            <a href="{{ route('driver', ['id' => $line->purchasable->getKey()]) }}" class="hover:underline">{{ $line->title }}</a>
                                        @else
                                            {{ $line->title }}
                                        @endif
                                    </h3>
                                    <p class="text-sm text-gray-500 dark:text-zinc-400">
                                        {{ $line->isDigital ? 'Digital download' : 'Physical item, shipped by the seller' }}
                                    </p>
                                    @if ($line->priceChanged())
                                        <p class="text-sm text-amber-600 dark:text-amber-400">
                                            The price changed from {{ money($line->storedUnitPriceCents) }} to {{ money($line->unitPriceCents) }} since you added it.
                                        </p>
                                    @endif
                                    @foreach ($line->issues as $issue)
                                        <p class="text-sm text-red-600 dark:text-red-400">{{ $issue->message }}</p>
                                    @endforeach
                                </div>

                                <div class="flex items-center space-x-4">
                                    @if (! $line->isDigital && $line->purchasable)
                                        <input type="number" min="1" @if($line->maxQuantity) max="{{ $line->maxQuantity }}" @endif
                                               value="{{ $line->quantity }}"
                                               wire:change="updateQuantity({{ $line->id() }}, $event.target.value)"
                                               aria-label="Quantity"
                                               class="w-20 rounded border-gray-300 dark:bg-zinc-700 dark:border-zinc-600 dark:text-white">
                                    @endif
                                    <span class="font-medium dark:text-white">{{ money($line->lineTotalCents()) }}</span>
                                    <button
                                        wire:click="removeItem({{ $line->id() }})"
                                        class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach

                <!-- Cart Summary -->
                <div class="bg-gray-50 dark:bg-zinc-900 p-6">
                    <div class="flex justify-between dark:text-white">
                        <span>Subtotal</span>
                        <span>{{ money($summary->subtotalCents) }}</span>
                    </div>
                    @if ($summary->needsAddress)
                        <p class="mt-1 text-sm text-gray-500 dark:text-zinc-400">Shipping is calculated from your address in the next step.</p>
                    @endif

                    @if ($summary->hasIssues())
                        <p class="mt-4 text-sm text-red-600 dark:text-red-400">Fix the items marked above to continue.</p>
                    @elseif ($summary->needsAddress)
                        <a href="{{ route('checkout') }}"
                           class="mt-4 block w-full text-center bg-purple-600 text-white py-2 px-4 rounded hover:bg-purple-700">
                            Continue to shipping
                        </a>
                    @else
                        <form method="POST" action="{{ route('checkout.start') }}" class="mt-4">
                            @csrf
                            <input type="hidden" name="expected_total_cents" value="{{ $summary->totalCents() }}">
                            <button type="submit"
                                    class="w-full bg-purple-600 text-white py-2 px-4 rounded hover:bg-purple-700">
                                Checkout
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        @else
            <div class="text-center py-12">
                <p class="text-gray-500 dark:text-zinc-400">Your cart is empty</p>
                <a href="{{ route('designs') }}"
                   class="mt-4 inline-block text-purple-600 hover:text-purple-800 dark:text-purple-400 dark:hover:text-purple-300"
                >
                    Continue Shopping
                </a>
            </div>
        @endif
    </div>
    @endvolt
</x-layouts.marketing>
