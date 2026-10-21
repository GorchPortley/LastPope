<?php

use App\Support\Countries;
use App\Services\Marketplace\CartService;
use Illuminate\Validation\Rule;
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
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:120'],
            'form.line1' => ['required', 'string', 'max:190'],
            'form.line2' => ['nullable', 'string', 'max:190'],
            'form.city' => ['required', 'string', 'max:120'],
            'form.state' => ['nullable', 'string', 'max:120'],
            'form.postal_code' => ['required', 'string', 'max:20'],
            'form.country' => ['required', Rule::in(array_keys(Countries::all()))],
            'form.phone' => ['nullable', 'string', 'max:30'],
            'form.is_default' => ['boolean'],
        ])['form'];

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

<x-layouts.marketing>
    @volt('checkout')
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <h1 class="text-2xl font-bold mb-8 dark:text-white">Checkout</h1>

        @if (session()->has('error'))
            <div class="mb-4 bg-red-50 dark:bg-red-900/50 text-red-600 dark:text-red-400 p-4 rounded">
                {{ session('error') }}
                @foreach ((array) session('checkout_issues', []) as $issueMessage)
                    <div class="text-sm">{{ $issueMessage }}</div>
                @endforeach
            </div>
        @endif

        @if ($summary->isEmpty())
            <p class="text-gray-500 dark:text-zinc-400">Your cart is empty.
                <a href="{{ route('cart') }}" class="text-purple-600 hover:text-purple-800 dark:text-purple-400">Back to the cart</a>
            </p>
        @else
            <div class="grid gap-8 md:grid-cols-2">
                <div>
                    @if ($summary->needsAddress)
                        <h2 class="font-semibold mb-3 dark:text-white">Shipping address</h2>

                        <div class="space-y-2">
                            @foreach ($addresses as $saved)
                                <label wire:key="address-{{ $saved->id }}" class="block p-3 rounded border dark:text-white {{ $saved->id === $addressId && ! $adding ? 'border-purple-600' : 'border-gray-300 dark:border-zinc-600' }}">
                                    <input type="radio" name="address" value="{{ $saved->id }}" wire:click="selectAddress({{ $saved->id }})" @checked($saved->id === $addressId && ! $adding)>
                                    <span class="font-medium">{{ $saved->name }}</span>
                                    <span class="block text-sm text-gray-600 dark:text-zinc-400">
                                        {{ $saved->line1 }}{{ $saved->line2 ? ', '.$saved->line2 : '' }}, {{ $saved->postal_code }} {{ $saved->city }}{{ $saved->state ? ', '.$saved->state : '' }}, {{ Countries::name($saved->country) }}
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        @if (! $adding)
                            <button type="button" wire:click="$set('adding', true)" class="mt-3 text-sm text-purple-600 hover:text-purple-800 dark:text-purple-400">Add a new address</button>
                        @else
                            <form wire:submit="saveAddress" class="mt-4 space-y-3">
                                @foreach ([['name', 'Full name'], ['line1', 'Address'], ['line2', 'Apartment, suite (optional)'], ['city', 'City'], ['state', 'State / province (optional)'], ['postal_code', 'Postal code'], ['phone', 'Phone (optional)']] as [$field, $label])
                                    <div>
                                        <label class="block text-sm dark:text-white" for="form-{{ $field }}">{{ $label }}</label>
                                        <input id="form-{{ $field }}" type="text" wire:model="form.{{ $field }}" class="w-full rounded border-gray-300 dark:bg-zinc-700 dark:border-zinc-600 dark:text-white">
                                        @error('form.'.$field) <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                @endforeach
                                <div>
                                    <label class="block text-sm dark:text-white" for="form-country">Country</label>
                                    <select id="form-country" wire:model="form.country" class="w-full rounded border-gray-300 dark:bg-zinc-700 dark:border-zinc-600 dark:text-white">
                                        @foreach ($countries as $code => $countryName)
                                            <option value="{{ $code }}">{{ $countryName }}</option>
                                        @endforeach
                                    </select>
                                    @error('form.country') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <label class="flex items-center gap-2 text-sm dark:text-white">
                                    <input type="checkbox" wire:model="form.is_default"> Use as my default address
                                </label>
                                <button type="submit" class="bg-gray-800 text-white py-2 px-4 rounded hover:bg-gray-700">Save address</button>
                            </form>
                        @endif
                    @else
                        <p class="text-gray-600 dark:text-zinc-400">Everything in your cart is a digital download, so no shipping address is needed.</p>
                    @endif
                </div>

                <div>
                    <h2 class="font-semibold mb-3 dark:text-white">Order summary</h2>
                    <div class="bg-white dark:bg-zinc-800 rounded-lg shadow divide-y divide-gray-200 dark:divide-zinc-700">
                        @foreach ($summary->sellers as $group)
                            <div class="p-4" wire:key="group-{{ $group->seller?->getKey() ?? 0 }}">
                                <div class="text-sm font-medium text-gray-600 dark:text-zinc-400">Sold by {{ $group->displayName }}</div>
                                @foreach ($group->lines as $line)
                                    <div class="mt-1 flex justify-between dark:text-white">
                                        <span>{{ $line->quantity > 1 ? $line->quantity.' x ' : '' }}{{ $line->title }}</span>
                                        <span>{{ money($line->lineTotalCents()) }}</span>
                                    </div>
                                    @foreach ($line->issues as $issue)
                                        <p class="text-sm text-red-600 dark:text-red-400">{{ $issue->message }}</p>
                                    @endforeach
                                @endforeach
                                @if ($group->hasPhysicalItems())
                                    <div class="mt-1 flex justify-between text-sm text-gray-600 dark:text-zinc-400">
                                        <span>Shipping</span>
                                        <span>{{ $group->shippingPending ? 'choose an address' : money($group->shippingCents) }}</span>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                        <div class="p-4 flex justify-between font-semibold dark:text-white">
                            <span>Total</span>
                            <span>{{ money($summary->totalCents()) }}</span>
                        </div>
                    </div>

                    @if ($summary->canCheckout())
                        <form method="POST" action="{{ route('checkout.start') }}" class="mt-4">
                            @csrf
                            @if ($summary->needsAddress)
                                <input type="hidden" name="address_id" value="{{ $addressId }}">
                            @endif
                            <input type="hidden" name="expected_total_cents" value="{{ $summary->totalCents() }}">
                            <button type="submit" class="w-full bg-purple-600 text-white py-2 px-4 rounded hover:bg-purple-700">Pay {{ money($summary->totalCents()) }}</button>
                        </form>
                        <p class="mt-2 text-xs text-gray-500 dark:text-zinc-400">You pay securely on Stripe. Taxes, if any, are added there.</p>
                    @elseif ($summary->addressMissing())
                        <p class="mt-4 text-sm text-gray-600 dark:text-zinc-400">Choose or add a shipping address to continue.</p>
                    @else
                        <p class="mt-4 text-sm text-red-600 dark:text-red-400">Fix the items marked above in <a class="underline" href="{{ route('cart') }}">your cart</a> to continue.</p>
                    @endif
                </div>
            </div>
        @endif
    </div>
    @endvolt
</x-layouts.marketing>
