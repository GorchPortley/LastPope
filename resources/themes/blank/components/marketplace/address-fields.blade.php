{{--
    The fields of an address form bound to a Livewire array property (`form` by default).
    Used by the checkout page and by /settings/addresses. Needs $countries (code => name).
--}}
@props(['countries', 'prefix' => 'form', 'id' => 'address'])

@php
    $input = 'w-full border-gray-300 text-sm shadow-sm focus:border-purple-700 focus:ring-purple-700 dark:border-zinc-600 dark:bg-zinc-700 dark:text-white';
@endphp

<div {{ $attributes->class(['grid gap-4 sm:grid-cols-2']) }}>
    @foreach ([
        ['name', 'Full name', 'sm:col-span-2', 'name'],
        ['line1', 'Address', 'sm:col-span-2', 'address-line1'],
        ['line2', 'Apartment, suite, unit (optional)', 'sm:col-span-2', 'address-line2'],
        ['city', 'City', '', 'address-level2'],
        ['state', 'State / province (optional)', '', 'address-level1'],
        ['postal_code', 'Postal code', '', 'postal-code'],
    ] as [$field, $label, $span, $autocomplete])
        <div class="{{ $span }}">
            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-zinc-300" for="{{ $id }}-{{ $field }}">{{ $label }}</label>
            <input id="{{ $id }}-{{ $field }}" type="text" wire:model="{{ $prefix }}.{{ $field }}" autocomplete="{{ $autocomplete }}" class="{{ $input }}">
            @error($prefix.'.'.$field) <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
    @endforeach

    <div>
        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-zinc-300" for="{{ $id }}-country">Country</label>
        <select id="{{ $id }}-country" wire:model="{{ $prefix }}.country" autocomplete="country" class="{{ $input }}">
            <option value="">Choose a country</option>
            @foreach ($countries as $code => $countryName)
                <option value="{{ $code }}">{{ $countryName }}</option>
            @endforeach
        </select>
        @error($prefix.'.country') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>

    <div class="sm:col-span-2 sm:col-start-1">
        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-zinc-300" for="{{ $id }}-phone">Phone (optional, for the carrier)</label>
        <input id="{{ $id }}-phone" type="tel" wire:model="{{ $prefix }}.phone" autocomplete="tel" class="{{ $input }}">
        @error($prefix.'.phone') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>

    <label class="flex items-center gap-2 text-sm text-gray-700 sm:col-span-2 dark:text-zinc-300">
        <input type="checkbox" wire:model="{{ $prefix }}.is_default" class="border-gray-300 text-purple-700 focus:ring-purple-700">
        Use as my default address
    </label>
</div>
