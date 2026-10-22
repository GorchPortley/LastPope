<?php

use App\Http\Livewire\BuyBox;
use App\Models\Driver;
use App\Models\Order;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Notifications\OrderReceipt;
use App\Notifications\OrderShipped;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\ParcelPlanner;
use App\Services\Marketplace\PurchaseOrderService;
use App\Services\Marketplace\ShipmentService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| R3 part 2, buyer side: the buy box says when a product ships (and follows the quantity), the cart and the checkout show the
| parcels of every seller, the order page lists them with tracking and the expected window - and nobody ever sees a supplier.
*/

beforeEach(function () {
    Mail::fake();
    Notification::fake();
    config(['marketplace.store_name' => 'SDLabs', 'marketplace.physical_payout_trigger' => 'shipped', 'marketplace.fee_bps' => 1000]);
    $this->buyer = User::factory()->create();
    $this->admin = adminUser();
    $this->acme = supplier(['name' => 'Acme Hidden Wholesale', 'lead_time_min_days' => 5, 'lead_time_max_days' => 8, 'shipping_domestic_cents' => 1099, 'shipping_international_cents' => 3099, 'additional_item_shipping_cents' => 400]);
    $this->bolt = supplier(['name' => 'Bolt Secret Supply', 'lead_time_min_days' => 3, 'lead_time_max_days' => 4, 'shipping_domestic_cents' => 1299, 'shipping_international_cents' => null, 'additional_item_shipping_cents' => 0]);
    // the names of every supplier a buyer must never meet: the ones of these tests and the demo data's
    $this->secrets = ['Acme Hidden', 'Bolt Secret', 'Northwind Audio Wholesale', 'Harbor Pro Audio', 'northwind-audio', 'harborpro'];
});

/** A cart with a member's kit, own stock, an Acme product and a Bolt product. Returns the listings. */
function parcelCart(object $test): array
{
    $kit = sellableListing(onboardedSeller(), 15000, ['quantity' => 3, 'shipping_domestic_cents' => 1500, 'additional_item_shipping_cents' => 0, 'title' => 'Member Crossover Kit']);
    $stock = storeListing(['price_cents' => 8000, 'quantity' => 5, 'shipping_domestic_cents' => 900, 'additional_item_shipping_cents' => 0, 'title' => 'Stock Woofer 8'], null, $test->admin);
    $acme = dropshipListing($test->acme, ['price_cents' => 10000, 'cost_cents' => 6500, 'supplier_stock' => 10, 'title' => 'Dropped Midrange 5'], $test->admin);
    $bolt = dropshipListing($test->bolt, ['price_cents' => 3000, 'cost_cents' => 1800, 'title' => 'Dropped Tweeter 1'], $test->admin);

    fillCart($test->buyer, [[$kit, 1], [$stock, 2], [$acme, 1], [$bolt, 1]]);

    return compact('kit', 'stock', 'acme', 'bolt');
}

function assertNoSuppliers(string $html, object $test): void
{
    foreach ($test->secrets as $secret) {
        expect($html)->not->toContain($secret);
    }
}

// --- the buy box ------------------------------------------------------------------------------------------------

it('says when the product ships, for each way the store fulfils it', function () {
    $own = storeListing(['quantity' => 4, 'handling_days' => 2], null, $this->admin);
    $dropped = dropshipListing($this->acme, ['supplier_stock' => 7], $this->admin);
    $hybrid = hybridListing($this->acme, 3, ['supplier_stock' => 8], $this->admin);
    $member = sellableListing(onboardedSeller(), 5000, ['quantity' => 6]);

    $box = fn ($listing) => Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => $listing]);

    $box($own)->assertSee('In stock, ships in 2 days')->assertSeeHtml('data-buy-box-stock')->assertDontSee('4 available')->assertDontSee('Acme');
    $box($dropped)->assertSee('Ships in 5–8 business days')->assertDontSee('7 available')->assertDontSee('In stock')->assertDontSee('Acme');
    $box($hybrid)->assertSee('In stock, ships in 3 days');
    // a member's own listing keeps counting its units
    $box($member)->assertSee('6 available')->assertDontSee('ships in');
});

it('follows the chosen quantity: a hybrid listing flips from own stock to the supplier and says when it runs out', function () {
    $hybrid = hybridListing($this->acme, 3, ['supplier_stock' => 8, 'shipping_domestic_cents' => 700], $this->admin);

    $box = Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => $hybrid])
        ->assertSeeHtml('wire:model.live.debounce.300ms="quantity"')
        ->assertSee('In stock, ships in 3 days')->assertSee('$7.00 in-country');

    // 4 units are more than the own stock: the whole line comes from the supplier, with its lead time and its shipping
    $box->set('quantity', 4)
        ->assertSee('Ships in 5–8 business days')->assertDontSee('In stock, ships')
        ->assertSee('$10.99 in-country')->assertSee('$30.99 international');

    // down to 2 again
    $box->set('quantity', 2)->assertSee('In stock, ships in 3 days')->assertDontSee('Ships in 5–8');

    // more than anybody has
    $box->set('quantity', 9)->assertSee('Only 8 available');

    // an emptied input counts as one unit instead of breaking the box
    $box->set('quantity', '')->assertSee('In stock, ships in 3 days')->assertHasNoErrors();

    // what gets into the cart is what the box showed
    $box->set('quantity', 4)->call('add');
    $line = collect(app(CartService::class)->summary($this->buyer)->lines)->first();
    expect($line->quantity)->toBe(4)->and($line->fulfillment->isSupplier())->toBeTrue();
});

it('shows a dropshipped driver\'s box on its page without the supplier, and a sold-out one as out of stock', function () {
    $driver = Driver::factory()->create(['brand' => 'Dropprobe', 'model' => 'D2']);
    $listing = dropshipListing($this->acme, ['price_cents' => 9900, 'supplier_stock' => 5], $this->admin);
    $listing->update(['driver_id' => $driver->id]);

    $html = $this->actingAs($this->buyer)->get(route('driver', ['id' => $driver->id]))
        ->assertOk()->assertSee('Sold by SDLabs')->assertSee('$99.00')->assertSee('Ships in 5–8 business days')->assertSee('$10.99 in-country')
        ->getContent();
    assertNoSuppliers($html, $this);

    $listing->update(['supplier_stock' => 0]);
    $this->actingAs($this->buyer)->get(route('driver', ['id' => $driver->id]))->assertOk()->assertSee('Out of stock at SDLabs')->assertDontSee('Ships in 5–8');
});

// --- the cart and the checkout --------------------------------------------------------------------------------------

it('shows the cart per seller in parcels, with the shipping to come and when each line ships', function () {
    parcelCart($this);

    $html = $this->actingAs($this->buyer)->get(route('cart'))
        ->assertOk()
        ->assertSee('Ships from SDLabs')
        ->assertSee('Ships separately (5–8 business days)')
        ->assertSee('Ships separately (3–4 business days)')
        ->assertSee('Ships in 3 parcels')                       // the store's part of the cart
        ->assertSee('Shipping at checkout')
        ->assertSee('In stock, ships in 3 days')                // the own-stock line
        ->assertSee('Ships in 5–8 business days')               // an Acme line
        ->assertSee('Stock Woofer 8')->assertSee('Dropped Midrange 5')->assertSee('Dropped Tweeter 1')->assertSee('Member Crossover Kit')
        ->assertSeeHtml('data-parcel="stock"')
        ->getContent();

    // a member's part is one parcel named after the shop
    expect(substr_count($html, 'data-parcel-label'))->toBe(4);
    assertNoSuppliers($html, $this);
});

it('keeps a cart without dropshipping looking as before, one parcel per seller', function () {
    $kit = sellableListing(onboardedSeller(), 15000, ['quantity' => 3]);
    fillCart($this->buyer, [$kit]);

    $this->actingAs($this->buyer)->get(route('cart'))->assertOk()->assertSee('Ships from')->assertDontSee('Ships separately (')->assertDontSee('Ships in 2 parcels');
});

it('prices every parcel at checkout and names none of the suppliers', function () {
    parcelCart($this);
    $address = addressFor($this->buyer);

    $html = $this->actingAs($this->buyer)->get(route('checkout'))
        ->assertOk()
        ->assertSee('Ships from SDLabs')->assertSee('Ships separately (5–8 business days)')->assertSee('Ships separately (3–4 business days)')
        ->assertSeeInOrder(['Stock Woofer 8', '$9.00', 'Dropped Midrange 5', '$10.99', 'Dropped Tweeter 1', '$12.99'])
        ->assertSeeHtml('data-parcel-shipping')
        ->assertDontSee('choose an address')
        ->getContent();

    $summary = app(CartService::class)->summary($this->buyer, $address);
    expect($summary->shippingGroups())->toHaveCount(4) // the kit, own stock, Acme, Bolt
        ->and(collect($summary->shippingGroups())->map->label()->all())->toContain('Ships from SDLabs', 'Ships separately (5–8 business days)', 'Ships separately (3–4 business days)');
    assertNoSuppliers($html, $this);

    // without an address the parcels wait for one
    $this->buyer->addresses()->delete();
    $this->actingAs($this->buyer)->get(route('checkout'))->assertOk()->assertSee('Ships separately (5–8 business days)')->assertSee('choose an address');
});

// --- the order page ---------------------------------------------------------------------------------------------

/** Own stock (2 units) + one Acme unit + one Bolt unit, paid: three parcels. */
function parcelOrder(object $test): Order
{
    $stock = storeListing(['price_cents' => 8000, 'quantity' => 5, 'title' => 'Stock Woofer 8', 'shipping_domestic_cents' => 900, 'additional_item_shipping_cents' => 0], null, $test->admin);
    $acme = dropshipListing($test->acme, ['price_cents' => 10000, 'cost_cents' => 6000, 'supplier_stock' => 9, 'title' => 'Dropped Midrange 5'], $test->admin);
    $bolt = dropshipListing($test->bolt, ['price_cents' => 3000, 'cost_cents' => 1800, 'title' => 'Dropped Tweeter 1'], $test->admin);

    return paidOrderOf($test->buyer, [[$stock, 2], [$acme, 1], [$bolt, 1]]);
}

it('lists the parcels of a store order with their items, status and the window a supplier promises', function () {
    $order = parcelOrder($this);
    $url = route('dashboard.orders.show', ['order' => $order->number]);
    $window = $order->paid_at->copy()->addWeekdays(5)->format('M j').' and '.$order->paid_at->copy()->addWeekdays(8)->format('M j');

    $html = $this->actingAs($this->buyer)->get($url)->assertOk()
        ->assertSee('Parcel 1 of 3')->assertSee('Parcel 2 of 3')->assertSee('Parcel 3 of 3')
        ->assertSee('Ships from SDLabs')->assertSee('Ships separately (5–8 business days)')->assertSee('Ships separately (3–4 business days)')
        ->assertSee('2 x Stock Woofer 8')->assertSee('Dropped Midrange 5')->assertSee('Dropped Tweeter 1')
        ->assertSee('Not shipped yet')
        ->assertSee('Expected to ship between '.$window.' (5–8 business days)')
        ->assertSee('Being prepared for shipment')
        ->assertSeeHtml('data-parcels')
        ->getContent();
    assertNoSuppliers($html, $this);
    foreach (SupplierOrder::where('order_id', $order->id)->pluck('number') as $number) {
        expect($html)->not->toContain($number);
    }

    $pos = app(PurchaseOrderService::class);
    $sellerOrder = $order->sellerOrders->first();

    // the own parcel ships
    app(ShipmentService::class)->shipItems($this->admin, $sellerOrder, null, 'USPS', '9400PARCEL1', 'https://usps.example/9400PARCEL1');
    $this->actingAs($this->buyer)->get($url)->assertOk()
        ->assertSee('Partially shipped')->assertSee('1 parcel shipped')
        ->assertSee('9400PARCEL1')->assertSee('Track parcel')->assertSee('https://usps.example/9400PARCEL1', false)
        ->assertSee('Shipped')->assertSee('Not shipped yet')
        ->assertDontSee('Confirm received');

    // both suppliers ship
    foreach ([$this->acme, $this->bolt] as $i => $supplier) {
        $po = purchaseOrderOf($order, $supplier);
        $pos->markPlacedManually($this->admin, $po, 'REF-'.$i);
        $pos->markShipped($this->admin, $po->fresh(), 'UPS', '1ZSUP'.$i);
    }

    $html = $this->actingAs($this->buyer)->get($url)->assertOk()
        ->assertSee('1ZSUP0')->assertSee('1ZSUP1')->assertSee('9400PARCEL1')
        ->assertDontSee('Not shipped yet')->assertDontSee('Being prepared')->assertDontSee('Expected to ship')
        ->assertSee('Confirm received')
        ->getContent();
    expect(substr_count($html, 'Track parcel'))->toBe(1); // only the first parcel has a tracking link
    assertNoSuppliers($html, $this);
});

it('shows the expected window of a single supplier parcel and keeps a plain order simple', function () {
    $order = paidOrderOf($this->buyer, [[dropshipListing($this->acme, ['supplier_stock' => 4, 'title' => 'Lonely Dropped Item'], $this->admin), 1]]);

    $this->actingAs($this->buyer)->get(route('dashboard.orders.show', ['order' => $order->number]))->assertOk()
        ->assertSee('Waiting for the seller to ship')->assertSee('Expected to ship between')->assertSee('(5–8 business days)')
        ->assertDontSee('Parcel 1 of 1')->assertDontSee('Acme');

    $plain = paidOrderOf($this->buyer, [[storeListing(['quantity' => 3], null, $this->admin), 1]]);
    $this->actingAs($this->buyer)->get(route('dashboard.orders.show', ['order' => $plain->number]))->assertOk()
        ->assertSee('Waiting for the seller to ship')->assertDontSee('Expected to ship')->assertDontSee('Parcel 1 of');
});

it('lists the parcels of an order from two members the same way', function () {
    $a = sellableListing(onboardedSeller(), 4000, ['quantity' => 2]);
    $b = sellableListing(onboardedSeller(), 6000, ['quantity' => 2]);
    $order = paidOrderOf($this->buyer, [$a, $b]);

    // each member's part is its own seller order with one parcel: the simple view, as before
    $this->actingAs($this->buyer)->get(route('dashboard.orders.show', ['order' => $order->number]))->assertOk()
        ->assertSee('Waiting for the seller to ship')->assertDontSee('Parcel 1 of');
    expect($order->sellerOrders)->toHaveCount(2);
});

it('describes a parcel\'s expected shipping window without a supplier', function () {
    $order = parcelOrder($this);
    $parcels = app(ParcelPlanner::class)->parcels($order->sellerOrders->first());

    expect($parcels)->toHaveCount(3);
    [$own, $acme, $bolt] = $parcels->all();

    expect($own->expectedText($order->paid_at))->toBeNull()
        ->and($acme->expectedText(null))->toBe('Expected to ship in 5–8 business days')
        ->and($acme->expectedText(Illuminate\Support\Carbon::parse('2030-01-07')))->toBe('Expected to ship between Jan 14 and Jan 17 (5–8 business days)')
        ->and($bolt->expectedText(Illuminate\Support\Carbon::parse('2030-01-07')))->toBe('Expected to ship between Jan 10 and Jan 11 (3–4 business days)')
        ->and($acme->isShipped())->toBeFalse()->and($acme->isPartlyShipped())->toBeFalse();
});

// --- the mails --------------------------------------------------------------------------------------------------

it('lists the parcels and the expected windows in the order confirmation', function () {
    $order = parcelOrder($this);
    $mail = (string) (new OrderReceipt($order->fresh()))->toMail($this->buyer)->render();

    expect($mail)
        ->toContain('Your items ship in separate parcels')
        ->toContain('Ships from SDLabs')->toContain('2 x Stock Woofer 8')
        ->toContain('Ships separately (5–8 business days)')->toContain('Dropped Midrange 5')
        ->toContain('expected to ship between')
        ->not->toContain('Acme')->not->toContain('Bolt Secret');

    // one parcel: nothing to list
    $single = paidOrderOf($this->buyer, [[storeListing(['quantity' => 3], null, $this->admin), 1]]);
    expect((string) (new OrderReceipt($single))->toMail($this->buyer)->render())->not->toContain('separate parcels');
});

it('tells what is still to come in the shipping mail of a parcel', function () {
    $order = parcelOrder($this);
    $sellerOrder = $order->sellerOrders->first();
    $shipment = app(ShipmentService::class)->shipItems($this->admin, $sellerOrder, null, 'USPS', '9400PARCEL1');

    $mail = (string) (new OrderShipped($sellerOrder->fresh(['order', 'items']), $shipment))->toMail($this->buyer)->render();

    expect($mail)
        ->toContain('Parcel 1 of 3')
        ->toContain('Still to come')
        ->toContain('Dropped Midrange 5')->toContain('Dropped Tweeter 1')
        ->toContain('expected to ship between')
        ->not->toContain('2 x Stock Woofer 8 - expected') // what shipped is not "still to come"
        ->not->toContain('Acme')->not->toContain('Bolt Secret');

    // the last parcel has nothing left to announce
    foreach ([$this->acme, $this->bolt] as $supplier) {
        $po = purchaseOrderOf($order, $supplier);
        app(PurchaseOrderService::class)->markPlacedManually($this->admin, $po, 'REF');
        $last = app(PurchaseOrderService::class)->markShipped($this->admin, $po->fresh(), 'UPS', '1ZLAST');
    }
    $mail = (string) (new OrderShipped($sellerOrder->fresh(['order', 'items']), $last))->toMail($this->buyer)->render();
    expect($mail)->toContain('last parcel')->not->toContain('Still to come');
});

// --- the demo data ----------------------------------------------------------------------------------------------------

it('never shows the demo suppliers on the demo shop\'s pages: drivers, carts, orders', function () {
    // the demo data has dropshipped drivers (two suppliers) and store orders with purchase orders in several states
    $demoBuyers = User::whereIn('email', ['buyer1@sdlabs.test', 'buyer2@sdlabs.test', 'buyer3@sdlabs.test', 'buyer4@sdlabs.test'])->get();
    expect($demoBuyers)->not->toBeEmpty();

    $orders = Order::whereIn('user_id', $demoBuyers->pluck('id'))->whereHas('supplierOrders')->get();
    expect($orders)->not->toBeEmpty();

    foreach ($orders as $order) {
        $html = $this->actingAs($order->user)->get(route('dashboard.orders.show', ['order' => $order->number]))->assertOk()->getContent();
        assertNoSuppliers($html, $this);
        foreach ($order->supplierOrders as $po) {
            expect($html)->not->toContain($po->number);
        }
        // the e-mails
        assertNoSuppliers((string) (new OrderReceipt($order))->toMail($order->user)->render(), $this);
    }

    $dropshipped = Driver::whereHas('listings', fn ($q) => $q->dropshipped())->get();
    expect($dropshipped)->not->toBeEmpty();
    foreach ($dropshipped as $driver) {
        $html = $this->actingAs($demoBuyers->first())->get(route('driver', ['id' => $driver->id]))->assertOk()->getContent();
        assertNoSuppliers($html, $this);
    }
    assertNoSuppliers($this->actingAs($demoBuyers->first())->get(route('drivers'))->assertOk()->getContent(), $this);

    // a cart of the demo's dropshipped products, with an address, at the cart and the checkout
    $buyer = $demoBuyers->first();
    foreach (App\Models\Listing::store()->dropshipped()->forSale()->limit(3)->get() as $listing) {
        app(CartService::class)->add($buyer, $listing, 1);
    }
    foreach ([route('cart'), route('checkout')] as $url) {
        $html = $this->actingAs($buyer)->get($url)->assertOk()->assertSee('Ships separately')->getContent();
        assertNoSuppliers($html, $this);
    }
});
