<?php

use Illuminate\Support\Number;

if (! function_exists('money')) {
    /**
     * Format an amount of integer cents as a currency string, e.g. 1999 => "$19.99".
     *
     * Uses the marketplace currency (config('marketplace.currency')) unless one is given.
     */
    function money(int $cents, ?string $currency = null): string
    {
        $currency = strtoupper($currency ?: (string) config('marketplace.currency', 'usd'));
        $amount = $cents / 100;

        if (extension_loaded('intl')) {
            return (string) Number::currency($amount, $currency);
        }

        $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CAD' => 'CA$', 'AUD' => 'A$'];
        $formatted = number_format(abs($amount), 2);
        $symbol = $symbols[$currency] ?? $currency.' ';

        return ($cents < 0 ? '-' : '').$symbol.$formatted;
    }
}
