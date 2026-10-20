<?php

namespace App\Filament\Forms;

use Closure;
use Filament\Forms\Components\TextInput;

/**
 * A money field for Filament forms: shows dollars with two decimals, stores integer cents.
 *
 *     MoneyInput::make('price_cents')->label('Price')
 *     MoneyInput::make('msrp_cents')->nullable()   // empty input is stored as NULL instead of 0
 */
class MoneyInput extends TextInput
{
    protected bool|Closure $emptyIsNull = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->numeric()
            ->inputMode('decimal')
            ->step('0.01')
            ->minValue(0)
            ->prefix(fn (): string => static::currencySymbol())
            ->formatStateUsing(fn ($state): ?string => static::toDollars($state))
            ->dehydrateStateUsing(fn ($state): ?int => static::toCents($state, (bool) $this->evaluate($this->emptyIsNull)));
    }

    /**
     * Leave the column NULL when the field is empty (default: an empty field becomes 0 cents).
     */
    public function nullable(bool|Closure $condition = true): static
    {
        $this->emptyIsNull = $condition;

        return parent::nullable($condition);
    }

    /** Integer cents (or null) to the "19.99" string shown in the input. */
    public static function toDollars(mixed $cents): ?string
    {
        if ($cents === null || $cents === '') {
            return null;
        }

        return number_format(((int) $cents) / 100, 2, '.', '');
    }

    /** What the user typed ("19.99", "19,99", "$1,234.5") to integer cents. */
    public static function toCents(mixed $dollars, bool $emptyIsNull = false): ?int
    {
        if ($dollars === null || $dollars === '') {
            return $emptyIsNull ? null : 0;
        }

        if (is_string($dollars)) {
            $dollars = trim(str_replace(['$', '€', '£', ' '], '', $dollars));
            // "19,99" (decimal comma) vs "1,234.50" (thousands comma)
            $dollars = preg_match('/^\d+,\d{1,2}$/', $dollars)
                ? str_replace(',', '.', $dollars)
                : str_replace(',', '', $dollars);
        }

        if (! is_numeric($dollars)) {
            return $emptyIsNull ? null : 0;
        }

        return (int) str_replace('.', '', number_format((float) $dollars, 2, '.', ''));
    }

    protected static function currencySymbol(): string
    {
        return trim((string) preg_replace('/[\d.,\s]/u', '', money(0))) ?: '$';
    }
}
