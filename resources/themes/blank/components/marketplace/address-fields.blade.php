{{--
    The fields of an address form bound to a Livewire array property (`form` by default), drawn with the
    Filament form inputs so they look like every other form of the site.
    Used by the checkout page and by /settings/addresses. Needs $countries (code => name).
--}}
@props(['countries', 'prefix' => 'form', 'id' => 'address'])

@php
    $label = 'mb-1 block text-sm font-medium leading-6 text-gray-950';
@endphp

<div {{ $attributes->class(['grid gap-4 sm:grid-cols-2']) }}>
    @foreach ([
        ['name', 'Full name', 'sm:col-span-2', 'name'],
        ['line1', 'Address', 'sm:col-span-2', 'address-line1'],
        ['line2', 'Apartment, suite, unit (optional)', 'sm:col-span-2', 'address-line2'],
        ['city', 'City', '', 'address-level2'],
        ['state', 'State / province (optional)', '', 'address-level1'],
        ['postal_code', 'Postal code', '', 'postal-code'],
    ] as [$field, $text, $span, $autocomplete])
        <div class="{{ $span }}">
            <label class="{{ $label }}" for="{{ $id }}-{{ $field }}">{{ $text }}</label>
            <x-filament::input.wrapper :valid="! $errors->has($prefix.'.'.$field)">
                <x-filament::input id="{{ $id }}-{{ $field }}" type="text" wire:model="{{ $prefix }}.{{ $field }}" autocomplete="{{ $autocomplete }}" />
            </x-filament::input.wrapper>
            @error($prefix.'.'.$field) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
    @endforeach

    <div>
        <label class="{{ $label }}" for="{{ $id }}-country">Country</label>
        <x-filament::input.wrapper :valid="! $errors->has($prefix.'.country')">
            <x-filament::input.select id="{{ $id }}-country" wire:model="{{ $prefix }}.country" autocomplete="country">
                <option value="">Choose a country</option>
                @foreach ($countries as $code => $countryName)
                    <option value="{{ $code }}">{{ $countryName }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
        @error($prefix.'.country') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    <div class="sm:col-span-2 sm:col-start-1">
        <label class="{{ $label }}" for="{{ $id }}-phone">Phone (optional, for the carrier)</label>
        <x-filament::input.wrapper :valid="! $errors->has($prefix.'.phone')">
            <x-filament::input id="{{ $id }}-phone" type="tel" wire:model="{{ $prefix }}.phone" autocomplete="tel" />
        </x-filament::input.wrapper>
        @error($prefix.'.phone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    <label class="flex items-center gap-2 text-sm text-gray-950 sm:col-span-2">
        <x-filament::input.checkbox wire:model="{{ $prefix }}.is_default" />
        Use as my default address
    </label>
</div>
