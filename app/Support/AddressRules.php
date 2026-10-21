<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/** Validation rules of the address forms (checkout and /settings/addresses). */
final class AddressRules
{
    /**
     * @param  string  $prefix  the Livewire array property the fields live in, e.g. "form" => "form.name"
     * @return array<string, array<int, mixed>>
     */
    public static function for(string $prefix = 'form'): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'line1' => ['required', 'string', 'max:190'],
            'line2' => ['nullable', 'string', 'max:190'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'max:20'],
            'country' => ['required', Rule::in(array_keys(Countries::all()))],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_default' => ['boolean'],
        ];

        $prefixed = [];
        foreach ($rules as $field => $fieldRules) {
            $prefixed[$prefix.'.'.$field] = $fieldRules;
        }

        return $prefixed;
    }
}
