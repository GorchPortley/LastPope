<?php

use App\Enums\FulfillmentSource;
use App\Exceptions\Marketplace\CheckoutError;
use App\Exceptions\Marketplace\CheckoutException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\CheckoutService;
use App\Services\Marketplace\ShippingCalculator;

/*
| R3.2: within the platform seller's part of a cart, shipping is priced per fulfilment group - own stock by the listing
| rules, each supplier's lines as one parcel by the supplier's rules - and checkout sends one Stripe shipping line per
| group. The supplier is never named to the buyer.
*/

beforeEach(function () {
    config(['marketplace.store_name' => 'SDLabs', 'marketplace.fee_bps' => 1000, 'marketplace.fee_fixed_cents' => 0]);
    $this->buyer = User::factory()->create();
    $this->address = addressFor($this->buyer);
    $this->admin = adminUser();

    $this->acme = supplier(['name' => 'Acme Secret Wholesale', 'lead_time_min_days' => 5, 'lead_time_max_days' => 8, 'shipping_domestic_cents' => 899, 'shipping_international_cents' => 2499, 'additional_item_shipping_cents' => 300]);
    $this->bolt = supplier(['name' => 'Bolt Hidden Supply', 'lead_time_min_days' => 3, 'lead_time_max_days' => 4, 'shipping_domestic_cents' => 1299, 'shipping_international_cents' => null, 'additional_item_shipping_cents' => 0]);
});

/** The mixed cart: a member's kit, own stock, Acme (3 listings, one a hybrid that falls over), Bolt. Returns the listings. */
function mixedCart(object $test): array
{
    $kit = sellableListing(onboardedSeller(), 15000, ['quantity' => 3, 'shipping_domestic_cents' => 1500, 'additional_item_shipping_cents' => 0]);
    $stock = storeListing(['price_cents' => 8000, 'quantity' => 5, 'shipping_domestic_cents' => 900, 'additional_item_shipping_cents' => 700], null, $test->admin);
    $d1 = dropshipListing($test->acme, ['price_cents' => 10000, 'cost_cents' => 6500, 'supplier_stock' => 10], $test->admin);
    $d2 = dropshipListing($test->acme, ['price_cents' => 5000, 'cost_cents' => 3000], $test->admin);
    $hybrid = hybridListing($test->acme, 1, ['price_cents' => 7000, 'cost_cents' => 4000, 'supplier_stock' => 10], $test->admin);
    $d3 = dropshipListing($test->bolt, ['price_cents' => 3000, 'cost_cents' => 1800], $test->admin);

    fillCart($test->buyer, [[$kit, 1], [$stock, 2], [$d1, 2], [$d2, 1], [$hybrid, 2], [$d3, 1]]);

    return compact('kit', 'stock', 'd1', 'd2', 'hybrid', 'd3');
}

it('groups the store lines into own stock and one parcel per supplier, with labels that never name a supplier', function () {
    mixedCart($this);

    $summary = app(CartService::class)->summary($this->buyer, $this->address);

    expect($summary->canCheckout())->toBeTrue()
        ->and($summary->sellers)->toHaveCount(2);

    $store = collect($summary->sellers)->firstWhere('isPlatform', true);
    $member = collect($summary->sellers)->firstWhere('isPlatform', false);

    expect($member->shippingGroups)->toHaveCount(1)
        ->and($member->shippingGroups[0]->kind)->toBe('seller')
        ->and($member->shippingCents)->toBe(1500);

    [$own, $acme, $bolt] = $store->shippingGroups;

    // own stock: the listing rules (900 + 1 x 700)
    expect($own->kind)->toBe('stock')
        ->and($own->label())->toBe('Ships from SDLabs')
        ->and($own->shippingCents)->toBe(1600)
        ->and($own->subtotalCents)->toBe(16000)
        // Acme: 2 + 1 + 2 (the hybrid line falls over as a whole) = 5 units: 899 + 4 x 300
        ->and($acme->kind)->toBe('supplier')
        ->and($acme->label())->toBe('Ships separately (5–8 business days)')
        ->and($acme->shippingLabel())->toBe('Shipping (ships separately, 5–8 business days)')
        ->and($acme->unitCount())->toBe(5)
        ->and($acme->shippingCents)->toBe(899 + 4 * 300)
        ->and($acme->subtotalCents)->toBe(20000 + 5000 + 14000)
        ->and($bolt->label())->toBe('Ships separately (3–4 business days)')
        ->and($bolt->shippingCents)->toBe(1299)
        ->and($store->shippingCents)->toBe(1600 + 2099 + 1299)
        ->and($store->hasMultipleParcels())->toBeTrue()
        ->and($summary->shippingCents)->toBe(1500 + 1600 + 2099 + 1299)
        ->and($summary->shippingGroups())->toHaveCount(4)
        ->and($summary->hasMultipleParcels())->toBeTrue();

    // nothing the buyer sees names a supplier
    $labels = collect($summary->shippingGroups())->map(fn ($g) => $g->label().' '.$g->shippingLabel())->implode(' ');
    expect($labels)->not->toContain('Acme')->not->toContain('Bolt');

    // the hybrid line fell over to the supplier as a whole
    expect(collect($summary->lines)->where('quantity', 2)->where('unitPriceCents', 7000)->first()->fulfillmentSource())->toBe(FulfillmentSource::SUPPLIER);
});

it('sends one Stripe shipping line per parcel and keeps the marketplace seller line as it was', function () {
    $listings = mixedCart($this);

    $result = startCheckout($this->buyer, $this->address);
    $order = $result->order->fresh(['sellerOrders.items']);
    $lines = collect(fakeGateway()->lastCall('createCheckoutSession')['lineItems']);

    $shipping = $lines->filter(fn ($l) => str_starts_with($l['price_data']['product_data']['name'], 'Shipping'))
        ->mapWithKeys(fn ($l) => [$l['price_data']['product_data']['name'] => $l['price_data']['unit_amount']]);

    $member = $listings['kit']->seller->sellerProfile->shop_name;

    expect($shipping->all())->toBe([
        'Shipping - '.$member => 1500,
        'Shipping (ships from SDLabs)' => 1600,
        'Shipping (ships separately, 5–8 business days)' => 2099,
        'Shipping (ships separately, 3–4 business days)' => 1299,
    ]);

    // the Stripe lines add up to the order total
    expect($lines->sum(fn ($l) => $l['price_data']['unit_amount'] * $l['quantity']))->toBe($order->total_cents)
        ->and($order->shipping_cents)->toBe(1500 + 1600 + 2099 + 1299);

    // the supplier's name is nowhere in what Stripe (and so the buyer) sees
    $json = json_encode($lines->all());
    expect($json)->not->toContain('Acme')->not->toContain('Bolt')->not->toContain('Secret')->not->toContain('Hidden');

    // the shipping lines say which parcel they are for
    $groups = $lines->map(fn ($l) => $l['price_data']['product_data']['metadata']['shipping_group'] ?? null)->filter()->values()->all();
    expect($groups)->toBe(['stock', 'supplier', 'supplier']);
});

it('decides the source of every line at checkout and stores it with the cost, never splitting a line', function () {
    $listings = mixedCart($this);

    $order = startCheckout($this->buyer, $this->address)->order;
    $items = OrderItem::where('order_id', $order->id)->get()->keyBy('purchasable_id');

    $of = fn (string $key) => $items[$listings[$key]->id];

    expect($of('kit')->fulfillment_source)->toBe(FulfillmentSource::STOCK)
        ->and($of('stock')->fulfillment_source)->toBe(FulfillmentSource::STOCK)
        ->and($of('stock')->supplier_id)->toBeNull()
        ->and($of('d1')->fulfillment_source)->toBe(FulfillmentSource::SUPPLIER)
        ->and($of('d1')->supplier_id)->toBe($this->acme->id)
        ->and($of('d1')->unit_cost_cents)->toBe(6500)
        ->and($of('hybrid')->fulfillment_source)->toBe(FulfillmentSource::SUPPLIER) // 2 asked, 1 in stock: the whole line
        ->and($of('hybrid')->quantity)->toBe(2)
        ->and($of('d3')->supplier_id)->toBe($this->bolt->id)
        ->and($of('stock')->unit_cost_cents)->toBeNull(); // no cost on this listing

    // the shares of the supplier parcel add up to what was charged
    $acmeShipping = $items->filter(fn ($i) => $i->supplier_id === $this->acme->id)->sum('shipping_cents');
    expect($acmeShipping)->toBe(2099)
        ->and($of('stock')->shipping_cents)->toBe(1600);

    // one hybrid listing, one cart line, one order item - own stock was not touched by the pending order
    expect($listings['hybrid']->fresh()->quantity)->toBe(1);
});

it('keeps a hybrid line in own stock while it fits', function () {
    $hybrid = hybridListing($this->acme, 3, ['supplier_stock' => 10, 'handling_days' => 2], $this->admin);
    fillCart($this->buyer, [[$hybrid, 2]]);

    $summary = app(CartService::class)->summary($this->buyer, $this->address);
    $group = $summary->sellers[0]->shippingGroups[0];

    // own stock: the listing's own shipping rules (the factory's 899 + 300), a "Ships from SDLabs" parcel
    expect($group->kind)->toBe('stock')
        ->and($group->shippingCents)->toBe($hybrid->shippingCentsFor('US', 2))
        ->and($summary->lines[0]->fulfillmentSource())->toBe(FulfillmentSource::STOCK);
});

it('prices a supplier parcel: free over the threshold, flat when no further-unit price, none abroad when domestic only', function () {
    $calculator = app(ShippingCalculator::class);
    $free = supplier(['shipping_domestic_cents' => 1000, 'additional_item_shipping_cents' => 500, 'free_shipping_over_cents' => 15000]);
    $below = dropshipListing($free, ['price_cents' => 7000]);
    $above = dropshipListing($free, ['price_cents' => 9000]);

    // 7000 + 9000 = 16000 >= 15000: free; 7000 alone: 1000
    $quote = $calculator->quote(['a' => [$below, 1], 'b' => [$above, 1]], 'US');
    expect($quote->lines)->toBe(['a' => 0, 'b' => 0])->and($quote->totalCents())->toBe(0);
    // 14000 is under the threshold: 1000 + 500 for the second unit; the 9000 one alone is under it too
    expect($calculator->quote(['a' => [$below, 2]], 'US')->lines['a'])->toBe(1500)
        ->and($calculator->quote(['b' => [$above, 1]], 'US')->lines['b'])->toBe(1000)
        // exactly at the threshold counts as over it
        ->and($calculator->quote(['c' => [dropshipListing($free, ['price_cents' => 15000]), 1]], 'US')->lines['c'])->toBe(0);
});

it('splits a supplier parcel over its lines so the shares add up', function () {
    $calculator = app(ShippingCalculator::class);
    $s = supplier(['shipping_domestic_cents' => 1000, 'additional_item_shipping_cents' => 450]);
    $a = dropshipListing($s, ['price_cents' => 1000]);
    $b = dropshipListing($s, ['price_cents' => 1000]);
    $c = dropshipListing($s, ['price_cents' => 1000]);

    $quote = $calculator->quote(['a' => [$a, 2], 'b' => [$b, 1], 'c' => [$c, 3]], 'US');

    // 6 units: 1000 + 5 x 450 = 3250, one parcel
    expect(array_sum($quote->lines))->toBe(3250)
        ->and($quote->supplierParcels[$s->id]['cents'])->toBe(3250)
        ->and($quote->supplierParcels[$s->id]['lines'])->toBe(['a', 'b', 'c'])
        ->and($quote->forSeller($a->seller_id))->toBe(3250)
        ->and($quote->isComplete())->toBeTrue();
});

it('reports supplier lines that cannot be shipped abroad, or priced before the address is known', function () {
    $calculator = app(ShippingCalculator::class);
    $domesticOnly = dropshipListing($this->bolt);

    $abroad = $calculator->quote(['x' => [$domesticOnly, 1]], 'FR');
    expect($abroad->lines['x'])->toBeNull()->and($abroad->unshippable)->toBe(['x'])->and($abroad->isComplete())->toBeFalse();

    $noAddress = $calculator->quote(['x' => [$domesticOnly, 1]], null);
    expect($noAddress->awaitingAddress)->toBe(['x']);

    // end to end: checkout refuses it with the not-shippable message
    fillCart($this->buyer, [$domesticOnly]);
    $french = addressFor($this->buyer, 'FR', false);
    expect(app(CartService::class)->summary($this->buyer, $french)->canCheckout())->toBeFalse();
    try {
        startCheckout($this->buyer, $french);
        $this->fail('Expected the checkout to be refused.');
    } catch (CheckoutException $e) {
        expect($e->error)->toBe(CheckoutError::NOT_SHIPPABLE);
    }

    // abroad with an international price the same supplier parcel is priced
    $abroadOk = dropshipListing($this->acme);
    $quote = $calculator->quote(['y' => [$abroadOk, 2]], 'FR');
    expect($quote->lines['y'])->toBe(2499 + 300);
});

it('refuses to charge a total the buyer has not seen when the last own units go elsewhere before the payment starts', function () {
    $hybrid = hybridListing($this->acme, 3, ['supplier_stock' => 10], $this->admin);
    fillCart($this->buyer, [[$hybrid, 2]]);
    $summary = app(CartService::class)->summary($this->buyer, $this->address);
    expect($summary->lines[0]->fulfillmentSource())->toBe(FulfillmentSource::STOCK);

    // meanwhile somebody else bought two of the three: now the line would come from the supplier
    $hybrid->update(['quantity' => 1]);

    $method = new ReflectionMethod(CheckoutService::class, 'assertStockAvailable');
    $method->setAccessible(true);

    expect(fn () => $method->invoke(app(CheckoutService::class), $this->buyer, $summary))
        ->toThrow(fn (CheckoutException $e) => $e->error === CheckoutError::PRICE_CHANGED);

    // the whole checkout, started again, uses the new source with its shipping
    $order = startCheckout($this->buyer, $this->address)->order;
    expect(OrderItem::where('order_id', $order->id)->sole()->fulfillment_source)->toBe(FulfillmentSource::SUPPLIER)
        ->and(Order::find($order->id)->shipping_cents)->toBe(899 + 300);
});
