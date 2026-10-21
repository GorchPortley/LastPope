<?php

use App\Support\AddressRules;
use App\Support\Countries;
use App\Services\Marketplace\CartService;
use function Laravel\Folio\{middleware, name};
use Livewire\Volt\Component;

middleware('auth');
name('checkout');

new class extends Component
{
    public ?int $addressId = null;

    public bool $adding = false;

    /** @var array<string, mixed> */
    public array $form = [
        'name' => '', 'line1' => '', 'line2' => '', 'city' => '', 'state' => '',
        'postal_code' => '', 'country' => '', 'phone' => '', 'is_default' => false,
    ];

    public function mount(): void
    {
        $user = auth()->user();
        $addresses = $user->addresses()->orderByDesc('is_default')->orderBy('id')->get();

        $this->addressId = $addresses->first()?->id;
        $this->adding = $addresses->isEmpty();
        $this->form['name'] = (string) $user->name;
        $this->form['country'] = strtoupper((string) config('marketplace.default_country', 'US'));
    }

    public function selectAddress(int $id): void
    {
        if (auth()->user()->addresses()->whereKey($id)->exists()) {
            $this->addressId = $id;
            $this->adding = false;
        }
    }

    public function saveAddress(): void
    {
        $data = $this->validate(AddressRules::for('form'))['form'];

        $address = auth()->user()->addresses()->create($data + ['is_default' => (bool) ($data['is_default'] ?? false)]);

        $this->addressId = $address->id;
        $this->adding = false;
    }

    public function with(): array
    {
        $user = auth()->user();
        $address = $this->addressId ? $user->addresses()->find($this->addressId) : null;

        return [
            'addresses' => $user->addresses()->orderByDesc('is_default')->orderBy('id')->get(),
            'address' => $address,
            'summary' => app(CartService::class)->summary($user, $address),
            'countries' => Countries::all(),
        ];
    }
}; ?>

<x-layouts.marketing :seo="['title' => 'Checkout - '.config('app.name')]">
    @volt('checkout')
    <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
        <h1 class="mb-6 text-3xl font-bold text-gray-900 dark:text-white">Checkout</h1>

        @if ($summary->isEmpty())
            <div class="border border-dashed border-gray-300 bg-white py-14 text-center dark:border-zinc-600 dark:bg-zinc-800">
                <p class="text-lg font-medium text-gray-900 dark:text-white">Your cart is empty</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-zinc-400">There is nothing to pay for yet.</p>
                <x-button tag="a" href="{{ route('cart') }}" class="mt-4">Back to the cart</x-button>
            </div>
        @else
            <div class="gap-8 lg:grid lg:grid-cols-[minmax(0,1fr)_24rem]">
                <div class="space-y-6">
                    <section class="border border-gray-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                        @if ($summary->needsAddress)
                            <h2 class="flex items-center gap-2 text-lg font-semibold text-gray-900 dark:text-white"><x-phosphor-truck class="h-5 w-5" /> Shipping address</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-zinc-400">Sellers ship physical items here. Prices for shipping depend on the country.</p>

                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                @foreach ($addresses as $saved)
                                    @php($selected = $saved->id === $addressId && ! $adding)
                                    <label wire:key="address-{{ $saved->id }}" @class([
                                        'relative block cursor-pointer border p-4 text-sm text-gray-800 dark:text-zinc-100',
                                        'border-purple-950 bg-purple-50 ring-1 ring-purple-950 dark:bg-purple-950/40 dark:border-purple-400 dark:ring-purple-400' => $selected,
                                        'border-gray-300 hover:border-gray-400 dark:border-zinc-600' => ! $selected,
                                    ])>
                                        <input type="radio" name="address" value="{{ $saved->id }}" wire:click="selectAddress({{ $saved->id }})" @checked($selected) class="absolute right-3 top-3 text-purple-800 focus:ring-purple-700">
                                        <span class="block pr-6 font-semibold">{{ $saved->name }}</span>
                                        <span class="mt-1 block text-gray-600 dark:text-zinc-400">
                                            {{ $saved->line1 }}{{ $saved->line2 ? ', '.$saved->line2 : '' }}<br>
                                            {{ $saved->postal_code }} {{ $saved->city }}{{ $saved->state ? ', '.$saved->state : '' }}<br>
                                            {{ Countries::name($saved->country) }}
                                        </span>
                                        @if ($saved->is_default)
                                            <x-marketplace.badge class="mt-2">Default</x-marketplace.badge>
                                        @endif
                                    </label>
                                @endforeach
                            </div>

                            @if (! $adding)
                                <button type="button" wire:click="$set('adding', true)" class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-purple-700 hover:text-purple-900 dark:text-purple-300">
                                    <x-phosphor-plus class="h-4 w-4" /> Add a new address
                                </button>
                            @else
                                <form wire:submit="saveAddress" class="mt-5 border-t border-gray-200 pt-5 dark:border-zinc-700">
                                    <h3 class="mb-3 font-semibold text-gray-900 dark:text-white">New address</h3>
                                    <x-marketplace.address-fields :countries="$countries" id="checkout-address" />
                                    <div class="mt-4 flex items-center gap-3">
                                        <x-button type="submit" wire:loading.attr="disabled" wire:target="saveAddress">Save address</x-button>
                                        @if ($addresses->isNotEmpty())
                                            <button type="button" wire:click="$set('adding', false)" class="text-sm text-gray-600 underline dark:text-zinc-400">Cancel</button>
                                        @endif
                                    </div>
                                </form>
                            @endif
                        @else
                            <h2 class="flex items-center gap-2 text-lg font-semibold text-gray-900 dark:text-white"><x-phosphor-download-simple class="h-5 w-5" /> Digital delivery</h2>
                            <p class="mt-1 text-gray-600 dark:text-zinc-400">Everything in your cart is a digital download, so no shipping address is needed. The files appear in your library right after payment.</p>
                        @endif
                    </section>
                </div>

                <aside class="mt-8 lg:mt-0">
                    <div class="border border-gray-200 bg-white dark:border-zinc-700 dark:bg-zinc-800 lg:sticky lg:top-6">
                        <h2 class="border-b border-gray-200 px-5 py-4 text-lg font-semibold text-gray-900 dark:border-zinc-700 dark:text-white">Order summary</h2>

                        <div class="divide-y divide-gray-200 dark:divide-zinc-700">
                            @foreach ($summary->sellers as $group)
                                <div class="space-y-2 p-5 text-sm" wire:key="group-{{ $group->seller?->getKey() ?? 0 }}">
                                    <div class="font-medium text-gray-600 dark:text-zinc-400">Sold by {{ $group->displayName }}</div>
                                    @foreach ($group->lines as $line)
                                        <div wire:key="line-{{ $line->id() }}">
                                            <div class="flex justify-between gap-3 text-gray-900 dark:text-white">
                                                <span class="min-w-0">{{ $line->quantity > 1 ? $line->quantity.' x ' : '' }}{{ $line->title }}</span>
                                                <span class="flex-shrink-0">{{ money($line->lineTotalCents()) }}</span>
                                            </div>
                                            @if ($line->priceChanged())
                                                <p class="text-amber-700 dark:text-amber-400">The price changed from {{ money($line->storedUnitPriceCents) }} to {{ money($line->unitPriceCents) }} since you added it.</p>
                                            @endif
                                            @foreach ($line->issues as $issue)
                                                <p class="text-red-600 dark:text-red-400">{{ $issue->message }}</p>
                                            @endforeach
                                        </div>
                                    @endforeach
                                    @if ($group->hasPhysicalItems())
                                        <div class="flex justify-between text-gray-600 dark:text-zinc-400">
                                            <span>Shipping</span>
                                            <span>{{ $group->shippingPending ? 'choose an address' : money($group->shippingCents) }}</span>
                                        </div>
                                    @endif
                                </div>
                            @endforeach

                            <dl class="space-y-2 p-5 text-sm">
                                <div class="flex justify-between text-gray-600 dark:text-zinc-400">
                                    <dt>Items</dt>
                                    <dd>{{ money($summary->subtotalCents) }}</dd>
                                </div>
                                @if ($summary->needsAddress)
                                    <div class="flex justify-between text-gray-600 dark:text-zinc-400">
                                        <dt>Shipping</dt>
                                        <dd>{{ $summary->shippingPending ? 'choose an address' : money($summary->shippingCents) }}</dd>
                                    </div>
                                @endif
                                <div class="flex justify-between border-t border-gray-200 pt-3 text-base font-bold text-gray-900 dark:border-zinc-700 dark:text-white">
                                    <dt>Total</dt>
                                    <dd data-checkout-total>{{ money($summary->totalCents()) }}</dd>
                                </div>
                            </dl>
                        </div>

                        <div class="border-t border-gray-200 p-5 dark:border-zinc-700">
                            @if ($summary->canCheckout())
                                <form method="POST" action="{{ route('checkout.start') }}">
                                    @csrf
                                    @if ($summary->needsAddress)
                                        <input type="hidden" name="address_id" value="{{ $addressId }}">
                                    @endif
                                    <input type="hidden" name="expected_total_cents" value="{{ $summary->totalCents() }}">
                                    <x-button type="submit" class="w-full" size="lg">
                                        <x-phosphor-lock-simple class="mr-1 inline h-4 w-4" /> Pay {{ money($summary->totalCents()) }} with Stripe
                                    </x-button>
                                </form>
                                <p class="mt-3 text-xs text-gray-500 dark:text-zinc-400">You pay securely on Stripe. Taxes, if any, are added there.</p>
                            @elseif ($summary->addressMissing())
                                <x-button class="w-full" color="gray" disabled>Pay with Stripe</x-button>
                                <p class="mt-3 text-sm text-gray-600 dark:text-zinc-400">Choose or add a shipping address to continue.</p>
                            @else
                                <x-button class="w-full" color="gray" disabled>Pay with Stripe</x-button>
                                <p class="mt-3 text-sm text-red-600 dark:text-red-400">Fix the items marked above in <a class="underline" href="{{ route('cart') }}">your cart</a> to continue.</p>
                            @endif
                            <a href="{{ route('cart') }}" class="mt-4 block text-center text-sm text-purple-700 underline hover:text-purple-900 dark:text-purple-300">Back to the cart</a>
                        </div>
                    </div>
                </aside>
            </div>
        @endif
    </div>
    @endvolt
</x-layouts.marketing>
