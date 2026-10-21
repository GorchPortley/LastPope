<?php

use App\Filament\Forms\MoneyInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Livewire\Component;

it('formats integer cents as money', function () {
    expect(money(1999))->toBe('$19.99')
        ->and(money(0))->toBe('$0.00')
        ->and(money(5))->toBe('$0.05')
        ->and(money(100000))->toBe('$1,000.00')
        ->and(money(123456789))->toBe('$1,234,567.89')
        ->and(money(-550))->toBe('-$5.50');
});

it('uses the marketplace currency unless one is given', function () {
    expect(money(1999, 'eur'))->toBe('€19.99')
        ->and(money(1999, 'GBP'))->toBe('£19.99');

    config(['marketplace.currency' => 'gbp']);
    expect(money(1999))->toBe('£19.99');
});

it('converts what a user types to cents and back', function () {
    expect(MoneyInput::toCents('19.99'))->toBe(1999)
        ->and(MoneyInput::toCents('19,99'))->toBe(1999)
        ->and(MoneyInput::toCents('1,234.5'))->toBe(123450)
        ->and(MoneyInput::toCents('$7'))->toBe(700)
        ->and(MoneyInput::toCents(12.5))->toBe(1250)
        ->and(MoneyInput::toCents('0.1'))->toBe(10)
        ->and(MoneyInput::toCents('1.005'))->toBe(101)      // rounded half up, no float surprises
        ->and(MoneyInput::toCents(''))->toBe(0)
        ->and(MoneyInput::toCents(null))->toBe(0)
        ->and(MoneyInput::toCents('', emptyIsNull: true))->toBeNull()
        ->and(MoneyInput::toCents('abc'))->toBe(0)
        ->and(MoneyInput::toDollars(1999))->toBe('19.99')
        ->and(MoneyInput::toDollars(5))->toBe('0.05')
        ->and(MoneyInput::toDollars(0))->toBe('0.00')
        ->and(MoneyInput::toDollars(null))->toBeNull();

    foreach ([0, 1, 9, 10, 99, 100, 101, 1999, 2500, 99999, 123456, 9999999] as $cents) {
        expect(MoneyInput::toCents(MoneyInput::toDollars($cents)))->toBe($cents);
    }
});

/** A bare Filament form host, to run MoneyInput through a real form (fill, validate, dehydrate). */
function moneyForm(array $fields): array
{
    $host = new class extends Component implements HasForms {
        use InteractsWithForms;

        public ?array $data = [];

        public function render(): string
        {
            return '<div></div>';
        }
    };

    $form = Form::make($host)->schema($fields)->statePath('data');

    return [$host, $form];
}

it('shows dollars and saves cents when used in a Filament form', function () {
    [$host, $form] = moneyForm([
        MoneyInput::make('price_cents'),
        MoneyInput::make('msrp_cents')->nullable(),
    ]);

    $form->fill(['price_cents' => 1999, 'msrp_cents' => null]);
    expect($host->data['price_cents'])->toBe('19.99')
        ->and($host->data['msrp_cents'])->toBeNull();

    // the user edits the field
    $host->data['price_cents'] = '24.50';
    expect($form->getState())->toBe(['price_cents' => 2450, 'msrp_cents' => null]);

    // an empty required-money field is 0 cents; an empty nullable one stays null
    $host->data = ['price_cents' => '', 'msrp_cents' => '12'];
    expect($form->getState())->toBe(['price_cents' => 0, 'msrp_cents' => 1200]);
});

it('starts at the default and prefixes the currency symbol', function () {
    [$host, $form] = moneyForm([MoneyInput::make('price_cents')->default(0)]);

    $form->fill();
    expect($host->data['price_cents'])->toBe('0.00')
        ->and($form->getComponent('data.price_cents')->getPrefixLabel())->toBe('$');

    config(['marketplace.currency' => 'eur']);
    expect($form->getComponent('data.price_cents')->getPrefixLabel())->toBe('€');
});

it('rejects negative amounts and text', function () {
    [$host, $form] = moneyForm([MoneyInput::make('price_cents')]);

    $host->data = ['price_cents' => '-3'];
    expect(fn () => $form->getState())->toThrow(Illuminate\Validation\ValidationException::class);

    $host->data = ['price_cents' => 'ten'];
    expect(fn () => $form->getState())->toThrow(Illuminate\Validation\ValidationException::class);
});
