<?php

use App\Models\Listing;
use App\Models\User;
use App\Services\Marketplace\FeeCalculator;
use App\Services\Marketplace\ShippingCalculator;

beforeEach(function () {
    config(['marketplace.fee_bps' => 1000, 'marketplace.fee_fixed_cents' => 0]);
});

// --- Fees -------------------------------------------------------------------------------------

it('charges the default percentage on items only', function () {
    $fees = app(FeeCalculator::class);
    $seller = onboardedSeller();

    expect($fees->feeCents(10000, $seller))->toBe(1000)
        ->and($fees->bpsFor($seller))->toBe(1000)
        // shipping never enters the fee, but the seller still receives it
        ->and($fees->payoutCents(10000, 899, $seller))->toBe(10000 + 899 - 1000);
});

it('rounds the fee half up', function (int $subtotal, int $expected) {
    expect(app(FeeCalculator::class)->feeCents($subtotal, onboardedSeller()))->toBe($expected);
})->with([
    'exactly .5 rounds up' => [1005, 101],
    'below .5 rounds down' => [1004, 100],
    'another .5' => [995, 100],
    'tiny' => [4, 0],
    'half of a cent' => [5, 1],
    'zero' => [0, 0],
]);

it('adds the fixed fee per seller order and never takes more than the items', function () {
    config(['marketplace.fee_fixed_cents' => 30]);
    $fees = app(FeeCalculator::class);
    $seller = onboardedSeller();

    expect($fees->feeCents(10000, $seller))->toBe(1030)
        ->and($fees->feeCents(100, $seller))->toBe(40)
        ->and($fees->feeCents(20, $seller))->toBe(20);
});

it('uses the seller commission override, including zero', function () {
    $fees = app(FeeCalculator::class);
    $cheap = User::factory()->onboardedSeller(['commission_bps' => 250])->create();
    $free = User::factory()->onboardedSeller(['commission_bps' => 0])->create();

    expect($fees->bpsFor($cheap))->toBe(250)
        ->and($fees->feeCents(10000, $cheap))->toBe(250)
        ->and($fees->feeCents(10000, $free))->toBe(0)
        ->and($fees->payoutCents(10000, 500, $free))->toBe(10500);
});

it('takes no fee and pays nothing out for platform sellers', function () {
    $admin = User::factory()->admin()->create();
    $fees = app(FeeCalculator::class);

    expect($fees->feeCents(10000, $admin))->toBe(0)
        ->and($fees->payoutCents(10000, 500, $admin))->toBe(0);
});

// --- Shipping ---------------------------------------------------------------------------------

it('prices domestic and international lines and adds the additional-item rate', function () {
    $listing = sellableListing(attributes: [
        'ships_from_country' => 'US',
        'shipping_domestic_cents' => 800,
        'shipping_international_cents' => 2500,
        'additional_item_shipping_cents' => 300,
    ]);
    $shipping = app(ShippingCalculator::class);

    expect($shipping->lineCents($listing, 1, 'US'))->toBe(800)
        ->and($shipping->lineCents($listing, 3, 'us'))->toBe(800 + 2 * 300)
        ->and($shipping->lineCents($listing, 1, 'DE'))->toBe(2500)
        ->and($shipping->lineCents($listing, 2, 'DE'))->toBe(2800);
});

it('reports lines the seller does not ship to, and lines that wait for an address', function () {
    $domesticOnly = sellableListing(attributes: ['shipping_international_cents' => null]);
    $global = sellableListing();
    $design = paidDesign();
    $shipping = app(ShippingCalculator::class);

    $quote = $shipping->quote(['a' => [$domesticOnly, 1], 'b' => [$global, 1], 'c' => [$design, 1]], 'FR');

    expect($quote->lines)->toBe(['a' => null, 'b' => 2499, 'c' => 0])
        ->and($quote->unshippable)->toBe(['a'])
        ->and($quote->awaitingAddress)->toBe([])
        ->and($quote->isComplete())->toBeFalse()
        ->and($quote->totalCents())->toBe(2499);

    $noAddress = $shipping->quote(['a' => [$domesticOnly, 1], 'c' => [$design, 1]], null);
    expect($noAddress->awaitingAddress)->toBe(['a'])
        ->and($noAddress->unshippable)->toBe([])
        ->and($noAddress->lines['c'])->toBe(0);
});

it('sums shipping per seller', function () {
    $sellerA = onboardedSeller();
    $sellerB = onboardedSeller();
    $a1 = sellableListing($sellerA, attributes: ['shipping_domestic_cents' => 500]);
    $a2 = sellableListing($sellerA, attributes: ['shipping_domestic_cents' => 700]);
    $b1 = sellableListing($sellerB, attributes: ['shipping_domestic_cents' => 900]);

    $quote = app(ShippingCalculator::class)->quote([[$a1, 1], [$a2, 1], [$b1, 1]], 'US');

    expect($quote->forSeller($sellerA->id))->toBe(1200)
        ->and($quote->forSeller($sellerB->id))->toBe(900)
        ->and($quote->totalCents())->toBe(2100);
});

it('delegates to Listing::shippingCentsFor', function () {
    $listing = Listing::factory()->make(['ships_from_country' => 'CA', 'shipping_domestic_cents' => 1100, 'shipping_international_cents' => 3100, 'additional_item_shipping_cents' => 0]);

    expect(app(ShippingCalculator::class)->lineCents($listing, 2, 'CA'))->toBe($listing->shippingCentsFor('CA', 2))
        ->and(app(ShippingCalculator::class)->lineCents($listing, 2, 'US'))->toBe(3100);
});
