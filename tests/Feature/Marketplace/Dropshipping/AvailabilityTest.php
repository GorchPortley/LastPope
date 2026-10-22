<?php

use App\Enums\FulfillmentMode;
use App\Enums\FulfillmentSource;
use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Marketplace\CartService;
use Illuminate\Validation\ValidationException;

/*
| R3.1: how many units can be bought and where they come from, for the three fulfilment modes (STOCK, DROPSHIP,
| STOCK_THEN_DROPSHIP), including the reservation of own stock and the limits of the supplier's stock.
*/

beforeEach(function () {
    config(['marketplace.dropship_unknown_stock_max' => 25]);
    $this->buyer = User::factory()->create();
});

it('keeps plain stock listings exactly as they were', function () {
    $listing = storeListing(['quantity' => 4, 'handling_days' => 2]);

    $quote = $listing->fulfillmentFor(3);

    expect($listing->fulfillmentMode())->toBe(FulfillmentMode::STOCK)
        ->and($listing->availableQuantity())->toBe(4)
        ->and($quote->source)->toBe(FulfillmentSource::STOCK)
        ->and($quote->available)->toBeTrue()
        ->and($quote->maxQuantity)->toBe(4)
        ->and([$quote->leadTimeMinDays, $quote->leadTimeMaxDays])->toBe([2, 2])
        ->and($quote->supplier)->toBeNull()
        ->and($listing->fulfillmentFor(5)->available)->toBeFalse()
        ->and($listing->shippingTextFor(3))->toBe('In stock, ships in 2 days')
        ->and($listing->shippingTextFor(5))->toBe('Only 4 available')
        ->and(storeListing(['quantity' => 0, 'status' => ListingStatus::SOLD_OUT])->shippingTextFor(1))->toBe('Out of stock');
});

it('dropships without own stock, limited by the supplier stock when it is known', function () {
    $supplier = supplier(['lead_time_min_days' => 5, 'lead_time_max_days' => 8]);
    $listing = dropshipListing($supplier, ['supplier_stock' => 6]);

    expect($listing->quantity)->toBe(0)
        ->and($listing->isAvailableForPurchase())->toBeTrue()
        ->and($listing->availableQuantity())->toBe(6);

    $quote = $listing->fulfillmentFor(4);
    expect($quote->source)->toBe(FulfillmentSource::SUPPLIER)
        ->and($quote->available)->toBeTrue()
        ->and($quote->maxQuantity)->toBe(6)
        ->and($quote->supplier->is($supplier))->toBeTrue()
        ->and([$quote->leadTimeMinDays, $quote->leadTimeMaxDays])->toBe([5, 8])
        ->and($quote->leadTimeText())->toBe('5–8 business days')
        ->and($listing->shippingTextFor(4))->toBe('Ships in 5–8 business days')
        ->and($listing->fulfillmentFor(7)->available)->toBeFalse()
        ->and($listing->shippingTextFor(7))->toBe('Only 6 available');
});

it('assumes a dropshipped product is available while the supplier stock is unknown, within the order limit', function () {
    $listing = dropshipListing(null, ['supplier_stock' => null]);

    expect($listing->isAvailableForPurchase())->toBeTrue()
        ->and($listing->availableQuantity())->toBe(25)
        ->and($listing->fulfillmentFor(25)->available)->toBeTrue()
        ->and($listing->fulfillmentFor(26)->available)->toBeFalse();

    config(['marketplace.dropship_unknown_stock_max' => 3]);
    expect($listing->availableQuantity())->toBe(3);
});

it('is unavailable when the supplier is out of stock or inactive', function () {
    $supplier = supplier();
    $outOfStock = dropshipListing($supplier, ['supplier_stock' => 0]);
    $other = dropshipListing(supplier(['active' => false]), ['supplier_stock' => 10]);

    expect($outOfStock->isAvailableForPurchase())->toBeFalse()
        ->and($outOfStock->shippingTextFor(1))->toBe('Out of stock')
        ->and($other->isAvailableForPurchase())->toBeFalse()
        ->and($other->availableQuantity())->toBe(0);
});

it('sells own stock first and falls over to the supplier for a whole line (never split)', function () {
    $supplier = supplier(['lead_time_min_days' => 4, 'lead_time_max_days' => 6]);
    $listing = hybridListing($supplier, 3, ['supplier_stock' => 10, 'handling_days' => 1]);

    $stock = $listing->fulfillmentFor(2);
    expect($stock->source)->toBe(FulfillmentSource::STOCK)
        ->and($stock->available)->toBeTrue()
        ->and($stock->supplier)->toBeNull()
        ->and($listing->shippingTextFor(2))->toBe('In stock, ships in 1 day');

    // 3 are in stock, 4 are not: all 4 come from the supplier, none from stock
    expect($listing->fulfillmentFor(3)->source)->toBe(FulfillmentSource::STOCK);
    $fallback = $listing->fulfillmentFor(4);
    expect($fallback->source)->toBe(FulfillmentSource::SUPPLIER)
        ->and($fallback->available)->toBeTrue()
        ->and($fallback->supplier->is($supplier))->toBeTrue()
        ->and($listing->shippingTextFor(4))->toBe('Ships in 4–6 business days')
        ->and($listing->availableQuantity())->toBe(10)
        ->and($listing->fulfillmentFor(11)->available)->toBeFalse();
});

it('limits a hybrid listing by the larger of own stock and supplier stock', function () {
    $listing = hybridListing(null, 5, ['supplier_stock' => 2]);

    expect($listing->availableQuantity())->toBe(5)
        ->and($listing->fulfillmentFor(5)->source)->toBe(FulfillmentSource::STOCK)
        ->and($listing->fulfillmentFor(6)->available)->toBeFalse()
        ->and($listing->fulfillmentFor(6)->maxQuantity)->toBe(5);

    $noStock = hybridListing(null, 0, ['supplier_stock' => 4]);
    expect($noStock->fulfillmentFor(1)->source)->toBe(FulfillmentSource::SUPPLIER)
        ->and($noStock->availableQuantity())->toBe(4);

    // a hybrid listing with an unusable supplier is just own stock
    $inactive = hybridListing(supplier(['active' => false]), 2);
    expect($inactive->availableQuantity())->toBe(2)->and($inactive->fulfillmentFor(3)->available)->toBeFalse();
});

it('holds own stock for a pending checkout, but only for lines that come from own stock', function () {
    $listing = hybridListing(null, 3, ['supplier_stock' => 20]);
    $other = User::factory()->create();

    // 2 of 3 own units are held by a running checkout
    $order = pendingOrder($other, [[$listing, 2]], addressFor($other));
    expect(OrderItem::where('order_id', $order->id)->sole()->fulfillment_source)->toBe(FulfillmentSource::STOCK)
        ->and($listing->ownAvailableQuantity())->toBe(1)
        ->and($listing->ownAvailableQuantity($other))->toBe(3) // their own checkout never blocks them
        ->and($listing->fulfillmentFor(2, $this->buyer)->source)->toBe(FulfillmentSource::SUPPLIER)
        ->and($listing->fulfillmentFor(1, $this->buyer)->source)->toBe(FulfillmentSource::STOCK);

    // a pending line that goes to the supplier holds no own stock
    $third = User::factory()->create();
    $supplierOrder = pendingOrder($third, [[$listing, 5]], addressFor($third));
    expect(OrderItem::where('order_id', $supplierOrder->id)->sole()->fulfillment_source)->toBe(FulfillmentSource::SUPPLIER)
        ->and($listing->ownAvailableQuantity())->toBe(1);

    // when the hold ends, the units are back
    Order::whereKey($order->id)->update(['reserved_until' => now()->subMinute()]);
    expect($listing->fresh()->ownAvailableQuantity())->toBe(3);
});

it('never lets a dropshipped sale decrement own stock', function () {
    $listing = hybridListing(null, 2, ['supplier_stock' => 10]);
    $order = paidOrderOf($this->buyer, [[$listing, 4]]);

    $listing->refresh();
    expect($order->items->sole()->fulfillment_source)->toBe(FulfillmentSource::SUPPLIER)
        ->and($listing->quantity)->toBe(2)
        ->and($listing->supplier_stock)->toBe(6) // known supplier stock goes down until the next feed sync
        ->and($listing->status)->toBe(ListingStatus::ACTIVE);
});

it('keeps a hybrid listing on sale when own stock runs out, and sells out a plain one', function () {
    $hybrid = hybridListing(null, 2, ['supplier_stock' => 10]);
    $plain = storeListing(['quantity' => 2]);

    paidOrderOf($this->buyer, [[$hybrid, 2]]);
    paidOrderOf(User::factory()->create(), [[$plain, 2]]);

    expect($hybrid->fresh()->quantity)->toBe(0)
        ->and($hybrid->fresh()->status)->toBe(ListingStatus::ACTIVE)
        ->and($hybrid->fresh()->isAvailableForPurchase())->toBeTrue()
        ->and($plain->fresh()->status)->toBe(ListingStatus::SOLD_OUT);
});

it('limits the cart quantity of a dropshipped product by the supplier stock', function () {
    $listing = dropshipListing(null, ['supplier_stock' => 3]);
    $carts = app(CartService::class);

    expect($carts->add($this->buyer, $listing, 3)->ok())->toBeTrue();
    $result = $carts->add($this->buyer, $listing, 1);
    expect($result->ok())->toBeFalse()->and($result->available)->toBe(3);

    $line = $carts->summary($this->buyer)->lines[0];
    expect($line->maxQuantity)->toBe(3)
        ->and($line->fulfillmentSource())->toBe(FulfillmentSource::SUPPLIER)
        ->and($line->isDropshipped())->toBeTrue();

    $listing->update(['supplier_stock' => 1]);
    expect($carts->summary($this->buyer)->errors())->not->toBeEmpty();
});

it('shows dropshipped products in the shop only while a supplier can ship them', function () {
    $supplier = supplier();
    $ok = dropshipListing($supplier, ['supplier_stock' => 5]);
    $unknown = dropshipListing($supplier, ['supplier_stock' => null]);
    $out = dropshipListing($supplier, ['supplier_stock' => 0]);
    $plain = storeListing(['quantity' => 3]);
    $plainEmpty = storeListing(['quantity' => 0]);
    $hybridEmpty = hybridListing($supplier, 0, ['supplier_stock' => 3]);
    $hybridNone = hybridListing($supplier, 0, ['supplier_stock' => 0]);
    $inactive = dropshipListing(supplier(['active' => false]), ['supplier_stock' => 5]);

    $forSale = Listing::store()->forSale()->pluck('id')->all();

    expect($forSale)->toContain($ok->id, $unknown->id, $plain->id, $hybridEmpty->id)
        ->not->toContain($out->id, $plainEmpty->id, $hybridNone->id, $inactive->id);

    // the cheap check the driver cards use
    expect($ok->inStock())->toBeTrue()->and($out->inStock())->toBeFalse()->and($plainEmpty->inStock())->toBeFalse()->and($hybridEmpty->inStock())->toBeTrue();
});

it('only lets store listings be dropshipped, and requires a supplier for them', function () {
    $store = Listing::factory()->dropship(supplier())->make();
    expect($store->channelRuleViolations())->toBe([]);

    $store->supplier_id = null;
    expect($store->channelRuleViolations())->toHaveKey('supplier_id')
        ->and(fn () => $store->validateChannelRules())->toThrow(ValidationException::class);

    $kit = Listing::factory()->make(['fulfillment' => FulfillmentMode::DROPSHIP, 'supplier_id' => supplier()->id]);
    expect($kit->channelRuleViolations())->toHaveKey('fulfillment');

    // a dropshipped listing needs no own quantity to be published; a plain one does
    $draft = Listing::factory()->dropship(supplier())->draft()->create(['price_cents' => 5000]);
    expect($draft->publishBlockers())->toBe([]);
    expect(storeListing(['quantity' => 0, 'status' => ListingStatus::DRAFT])->publishBlockers())->toContain('Quantity must be at least 1.');
});

it('computes the unit margin and warns below the minimum margin', function () {
    config(['marketplace.min_margin_bps' => 1500]);
    $listing = dropshipListing(null, ['price_cents' => 10000, 'cost_cents' => 8000]);

    expect($listing->unitMarginCents())->toBe(2000)
        ->and($listing->marginBps())->toBe(2000)
        ->and($listing->lowMarginWarning())->toBeNull()
        ->and($listing->unitMarginCents(9000))->toBe(1000)
        ->and($listing->marginBps(9000))->toBe(1111)
        ->and($listing->lowMarginWarning(9000))->toContain('Low margin')->toContain('11.1')
        ->and($listing->lowMarginWarning(7000))->toContain('loss')
        ->and($listing->lowMarginWarning(9400))->toContain('Low margin')
        ->and($listing->lowMarginWarning(9412))->toBeNull()
        ->and($listing->lowMarginWarning(11000))->toBeNull();

    $unknown = dropshipListing(null, ['cost_cents' => null]);
    expect($unknown->unitMarginCents())->toBeNull()->and($unknown->marginBps())->toBeNull()->and($unknown->lowMarginWarning())->toBeNull();
});
