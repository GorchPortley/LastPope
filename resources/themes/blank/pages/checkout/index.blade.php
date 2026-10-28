<?php

use App\Support\Analytics\Ga4;
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
    <x-marketplace.page :crumbs="[['Marketplace', route('marketplace')], ['Cart', route('cart')], ['Checkout', null]]">
        <div class="space-y-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Checkout</h1>
                <p class="mt-2 text-lg text-gray-600 italic">Check where it goes, then pay securely with Stripe.</p>
            </div>

            @if ($summary->isEmpty())
                <x-marketplace.empty-state heading="Your cart is empty" description="There is nothing to pay for yet." icon="phosphor-shopping-cart">
                    <x-button tag="a" href="{{ route('cart') }}">Back to the cart</x-button>
                </x-marketplace.empty-state>
            @else
                <x-analytics.events :events="[Ga4::payload('begin_checkout', Ga4::ecommerce(array_map(fn ($line) => Ga4::cartLine($line), $summary->lines)))]" />
                <div class="gap-8 lg:grid lg:grid-cols-[minmax(0,1fr)_24rem]">
                    <div class="space-y-6">
                        <x-marketplace.box>
                            @if ($summary->needsAddress)
                                <h2 class="flex items-center gap-2 text-xl font-semibold text-gray-900"><x-phosphor-truck class="h-5 w-5" /> Shipping address</h2>
                                <p class="mt-1 text-sm text-gray-600">Sellers ship physical items here. Prices for shipping depend on the country.</p>

                                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                    @foreach ($addresses as $saved)
                                        @php($selected = $saved->id === $addressId && ! $adding)
                                        <label wire:key="address-{{ $saved->id }}" @class([
                                            'relative block cursor-pointer rounded-lg border-2 p-4 text-sm text-gray-800',
                                            'border-brand-950 bg-brand-50' => $selected,
                                            'border-gray-100 hover:border-gray-300' => ! $selected,
                                        ])>
                                            <input type="radio" name="address" value="{{ $saved->id }}" wire:click="selectAddress({{ $saved->id }})" @checked($selected) class="absolute right-3 top-3 text-brand-800 focus:ring-brand-700">
                                            <span class="block pr-6 font-semibold">{{ $saved->name }}</span>
                                            <span class="mt-1 block text-gray-600">
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
                                    <button type="button" wire:click="$set('adding', true)" class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-gray-700 hover:text-gray-900">
                                        <x-phosphor-plus class="h-4 w-4" /> Add a new address
                                    </button>
                                @else
                                    <form wire:submit="saveAddress" class="mt-5 border-t border-gray-200 pt-5">
                                        <h3 class="mb-3 text-lg font-medium text-gray-900">New address</h3>
                                        <x-marketplace.address-fields :countries="$countries" id="checkout-address" />
                                        <div class="mt-4 flex items-center gap-3">
                                            <x-button type="submit" wire:loading.attr="disabled" wire:target="saveAddress">Save address</x-button>
                                            @if ($addresses->isNotEmpty())
                                                <button type="button" wire:click="$set('adding', false)" class="text-sm text-gray-600 underline">Cancel</button>
                                            @endif
                                        </div>
                                    </form>
                                @endif
                            @else
                                <h2 class="flex items-center gap-2 text-xl font-semibold text-gray-900"><x-phosphor-download-simple class="h-5 w-5" /> Digital delivery</h2>
                                <p class="mt-1 text-gray-600">Everything in your cart is a digital download, so no shipping address is needed. The files appear in your library right after payment.</p>
                            @endif
                        </x-marketplace.box>
                    </div>

                    <aside class="mt-8 lg:mt-0">
                        <x-marketplace.box padding="p-0" class="overflow-hidden lg:sticky lg:top-6">
                            <h2 class="border-b border-gray-200 px-5 py-4 text-xl font-semibold text-gray-900">Order summary</h2>

                            <div class="divide-y divide-gray-200">
                                @foreach ($summary->sellers as $group)
                                    {{-- One block per parcel (own stock / each supplier), then the lines that ship nowhere (downloads). --}}
                                    @php($parcels = $group->shippingGroups)
                                    @php($parceled = collect($parcels)->flatMap(fn ($parcel) => collect($parcel->lines)->map(fn ($line) => $line->id()))->all())
                                    @php($others = collect($group->lines)->reject(fn ($line) => in_array($line->id(), $parceled, true))->values())
                                    <div class="space-y-3 p-5 text-sm" wire:key="group-{{ $group->seller?->getKey() ?? 0 }}" data-checkout-seller>
                                        <div class="font-medium text-gray-600">Sold by {{ $group->displayName }}</div>

                                        @foreach ($parcels as $parcel)
                                            <div class="space-y-2 rounded-md bg-gray-50 p-3" wire:key="parcel-{{ $group->seller?->getKey() ?? 0 }}-{{ $parcel->key }}">
                                                <x-marketplace.parcel-heading :group="$parcel" />
                                                @foreach ($parcel->lines as $line)
                                                    <x-marketplace.checkout-line :line="$line" />
                                                @endforeach
                                                <div class="flex justify-between border-t border-gray-200 pt-2 text-gray-600">
                                                    <span>Shipping</span>
                                                    <span data-parcel-shipping>{{ $parcel->shippingPending ? 'choose an address' : ($parcel->shippingCents > 0 ? money($parcel->shippingCents) : 'free') }}</span>
                                                </div>
                                            </div>
                                        @endforeach

                                        @foreach ($others as $line)
                                            <x-marketplace.checkout-line :line="$line" />
                                        @endforeach
                                    </div>
                                @endforeach

                                <dl class="space-y-2 p-5 text-sm">
                                    <div class="flex justify-between text-gray-600">
                                        <dt>Items</dt>
                                        <dd>{{ money($summary->subtotalCents) }}</dd>
                                    </div>
                                    @if ($summary->needsAddress)
                                        <div class="flex justify-between text-gray-600">
                                            <dt>Shipping</dt>
                                            <dd>{{ $summary->shippingPending ? 'choose an address' : money($summary->shippingCents) }}</dd>
                                        </div>
                                    @endif
                                    <div class="flex justify-between border-t border-gray-200 pt-3 text-base font-bold text-gray-900">
                                        <dt>Total</dt>
                                        <dd data-checkout-total>{{ money($summary->totalCents()) }}</dd>
                                    </div>
                                </dl>
                            </div>

                            <div class="border-t border-gray-200 p-5">
                                @if ($summary->canCheckout())
                                    <form method="POST" action="{{ route('checkout.start') }}" @if ($summary->needsAddress) data-ga4-submit="{{ Ga4::attr('add_shipping_info', Ga4::ecommerce(array_map(fn ($line) => Ga4::cartLine($line), $summary->lines))) }}" @endif>
                                        @csrf
                                        @if ($summary->needsAddress)
                                            <input type="hidden" name="address_id" value="{{ $addressId }}">
                                        @endif
                                        <input type="hidden" name="expected_total_cents" value="{{ $summary->totalCents() }}">
                                        <x-button type="submit" class="w-full" size="lg">
                                            <x-phosphor-lock-simple class="mr-1 inline h-4 w-4" /> Pay {{ money($summary->totalCents()) }} with Stripe
                                        </x-button>
                                    </form>
                                    <p class="mt-3 text-xs text-gray-500">You pay securely on Stripe. Taxes, if any, are added there.</p>
                                @elseif ($summary->addressMissing())
                                    <x-button class="w-full" color="gray" disabled>Pay with Stripe</x-button>
                                    <p class="mt-3 text-sm text-gray-600">Choose or add a shipping address to continue.</p>
                                @else
                                    <x-button class="w-full" color="gray" disabled>Pay with Stripe</x-button>
                                    <p class="mt-3 text-sm text-red-600">Fix the items marked above in <a class="underline" href="{{ route('cart') }}">your cart</a> to continue.</p>
                                @endif
                                <a href="{{ route('cart') }}" class="mt-4 block text-center text-sm text-gray-500 hover:text-gray-700">Back to the cart</a>
                            </div>
                        </x-marketplace.box>
                    </aside>
                </div>
            @endif
        </div>
    </x-marketplace.page>
    @endvolt
</x-layouts.marketing>
