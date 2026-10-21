<?php

use App\Models\Address;
use App\Support\AddressRules;
use App\Support\Countries;
use Filament\Notifications\Notification;
use Livewire\Volt\Component;
use function Laravel\Folio\{middleware, name};

middleware('auth');
name('settings.addresses');

new class extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        $this->blankForm();
    }

    private function blankForm(): void
    {
        $this->form = [
            'name' => (string) auth()->user()->name, 'line1' => '', 'line2' => '', 'city' => '', 'state' => '',
            'postal_code' => '', 'country' => strtoupper((string) config('marketplace.default_country', 'US')),
            'phone' => '', 'is_default' => false,
        ];
        $this->resetValidation();
    }

    public function add(): void
    {
        $this->blankForm();
        $this->editingId = null;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $address = auth()->user()->addresses()->findOrFail($id);

        $this->form = [
            'name' => (string) $address->name, 'line1' => (string) $address->line1, 'line2' => (string) $address->line2,
            'city' => (string) $address->city, 'state' => (string) $address->state, 'postal_code' => (string) $address->postal_code,
            'country' => (string) $address->country, 'phone' => (string) $address->phone, 'is_default' => (bool) $address->is_default,
        ];
        $this->resetValidation();
        $this->editingId = $address->id;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->blankForm();
    }

    public function save(): void
    {
        $data = $this->validate(AddressRules::for('form'))['form'];
        $user = auth()->user();

        // The first address is the default one.
        $data['is_default'] = (bool) ($data['is_default'] ?? false) || ! $user->addresses()->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))->exists();

        if ($this->editingId) {
            $user->addresses()->findOrFail($this->editingId)->update($data);
        } else {
            $user->addresses()->create($data);
        }

        Notification::make()->success()->title($this->editingId ? 'Address updated' : 'Address added')->send();

        $this->cancel();
    }

    public function makeDefault(int $id): void
    {
        auth()->user()->addresses()->findOrFail($id)->update(['is_default' => true]);

        Notification::make()->success()->title('Default address changed')->send();
    }

    public function delete(int $id): void
    {
        $user = auth()->user();
        $address = $user->addresses()->findOrFail($id);
        $wasDefault = $address->is_default;
        $address->delete();

        if ($wasDefault) {
            $user->addresses()->orderBy('id')->first()?->update(['is_default' => true]);
        }

        if ($this->editingId === $id) {
            $this->cancel();
        }

        Notification::make()->success()->title('Address deleted')->send();
    }

    public function with(): array
    {
        return [
            'addresses' => auth()->user()->addresses()->orderByDesc('is_default')->orderBy('id')->get(),
            'countries' => Countries::all(),
        ];
    }
}; ?>

<x-layouts.app>
    <x-app.settings-layout
        title="Settings"
        description="Manage your account avatar, name, email, and more.">

        @volt('settings.addresses')
        <div class="mt-5 lg:px-10">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Shipping addresses</h3>
                    <p class="text-sm text-gray-500">Where the sellers send your orders. You pick one of these at checkout.</p>
                </div>
                @unless ($showForm)
                    <x-button wire:click="add" size="sm"><x-phosphor-plus class="mr-1 inline h-4 w-4" /> Add address</x-button>
                @endunless
            </div>

            @if ($showForm)
                <form wire:submit="save" class="mt-5 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5">
                    <h4 class="mb-4 text-base font-semibold text-gray-950">{{ $editingId ? 'Edit address' : 'New address' }}</h4>
                    <x-marketplace.address-fields :countries="$countries" id="settings-address" />
                    <div class="mt-5 flex items-center gap-3">
                        <x-button type="submit" wire:loading.attr="disabled" wire:target="save">Save address</x-button>
                        <x-button type="button" color="gray" wire:click="cancel">Cancel</x-button>
                    </div>
                </form>
            @endif

            @if ($addresses->isEmpty() && ! $showForm)
                <x-marketplace.empty-state heading="No addresses yet" description="Add one now to check out faster and filter the marketplace by what ships to you." icon="phosphor-map-pin" class="mt-5" />
            @endif

            <div class="mt-5 grid gap-4 md:grid-cols-2">
                @foreach ($addresses as $address)
                    <div class="flex flex-col rounded-xl bg-white p-5 text-sm shadow-sm ring-1 ring-gray-950/5" wire:key="address-{{ $address->id }}">
                        <div class="flex items-start justify-between gap-2">
                            <p class="font-semibold text-gray-950">{{ $address->name }}</p>
                            @if ($address->is_default)<x-marketplace.badge color="primary">Default</x-marketplace.badge>@endif
                        </div>
                        <address class="mt-2 flex-1 not-italic text-gray-600">
                            {{ $address->line1 }}@if (filled($address->line2)), {{ $address->line2 }}@endif<br>
                            {{ $address->postal_code }} {{ $address->city }}@if (filled($address->state)), {{ $address->state }}@endif<br>
                            {{ Countries::name($address->country) }}
                            @if (filled($address->phone))<br>{{ $address->phone }}@endif
                        </address>
                        <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-gray-100 pt-3">
                            <button type="button" wire:click="edit({{ $address->id }})" class="font-medium text-gray-950 hover:text-brand-700">Edit</button>
                            @unless ($address->is_default)
                                <button type="button" wire:click="makeDefault({{ $address->id }})" class="font-medium text-gray-700 hover:text-gray-950">Make default</button>
                            @endunless
                            <button type="button" wire:click="delete({{ $address->id }})" wire:confirm="Delete this address?" class="ml-auto font-medium text-red-600 hover:text-red-800">Delete</button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        @endvolt
    </x-app.settings-layout>
</x-layouts.app>
