<?php

use App\Http\Livewire\BuyBox;
use App\Models\Driver;
use App\Models\User;
use App\Services\Marketplace\PurchaseOrderService;
use App\Services\Marketplace\ShipmentService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| The buyer-facing pages keep working with dropshipped products and parcels (the proper redesign is R3 part 2): the buy
| box says when a dropshipped product ships instead of a stock count, the driver page and cards know when a supplier has
| stock, and the order page lists the parcels - without naming a supplier.
*/

beforeEach(function () {
    Mail::fake();
    Notification::fake();
    config(['marketplace.physical_payout_trigger' => 'shipped', 'marketplace.store_name' => 'SDLabs']);
    $this->buyer = User::factory()->create();
    $this->admin = adminUser();
    $this->acme = supplier(['name' => 'Acme Hidden Wholesale', 'lead_time_min_days' => 5, 'lead_time_max_days' => 8, 'shipping_domestic_cents' => 1099, 'shipping_international_cents' => 3099]);
});

it('says when a dropshipped product ships in the buy box, with the supplier\'s shipping prices and no supplier name', function () {
    $listing = dropshipListing($this->acme, ['supplier_stock' => 7], $this->admin);

    Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => $listing])
        ->assertSee('Ships in 5–8 business days')
        ->assertDontSee('7 available')
        ->assertSee('$10.99')->assertSee('$30.99')
        ->assertDontSee('Acme')
        ->assertSeeHtml('data-buy-box="add"');

    // own stock still shows its count
    Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => storeListing(['quantity' => 4], null, $this->admin)])->assertSee('4 available');

    // a hybrid listing shows what applies to one unit: own stock while there is some
    $hybrid = hybridListing($this->acme, 2, ['supplier_stock' => 7], $this->admin);
    Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => $hybrid])->assertSee('In stock, ships in 3 days');
    $hybrid->update(['quantity' => 0]);
    Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => $hybrid->fresh()])->assertSee('Ships in 5–8 business days');
});

it('shows a sold-out message when the supplier has none', function () {
    $listing = dropshipListing($this->acme, ['supplier_stock' => 0], $this->admin);

    Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => $listing])
        ->assertSee('Sold out')
        ->assertSeeHtml('data-buy-box="unavailable"');
});

it('sells a dropshipped driver from its page and lists it as in stock on the cards', function () {
    $driver = Driver::factory()->create(['brand' => 'Dropprobe', 'model' => 'D1']);
    dropshipListing($this->acme, ['price_cents' => 9900, 'supplier_stock' => 5], $this->admin)->update(['driver_id' => $driver->id]);

    $this->actingAs($this->buyer)->get(route('driver', ['id' => $driver->id]))
        ->assertOk()->assertSee('Sold by SDLabs')->assertSee('$99.00')->assertSee('Ships in 5–8 business days')->assertDontSee('Acme');

    $this->actingAs($this->buyer)->get(route('drivers').'?search=Dropprobe')->assertOk()->assertSee('In stock at SDLabs');

    // the supplier ran out: the page says so instead of selling it
    $driver->listings()->update(['supplier_stock' => 0]);
    $this->actingAs($this->buyer)->get(route('driver', ['id' => $driver->id]))->assertOk()->assertSee('Out of stock at SDLabs');
});

it('lists the parcels of a partly shipped order on the buyer\'s order page, without the supplier', function () {
    $stock = storeListing(['price_cents' => 8000, 'quantity' => 5], null, $this->admin);
    $dropped = dropshipListing($this->acme, ['supplier_stock' => 9, 'title' => 'Dropshipped Tweeter 25'], $this->admin);
    $order = paidOrderOf($this->buyer, [[$stock, 1], [$dropped, 1]]);
    $sellerOrder = $order->sellerOrders->first();
    $url = route('dashboard.orders.show', ['order' => $order->number]);

    // nothing shipped yet
    $this->actingAs($this->buyer)->get($url)->assertOk()->assertSee('Waiting for the seller to ship');

    // the own-stock parcel is out
    app(ShipmentService::class)->shipItems($this->admin, $sellerOrder, null, 'USPS', '9400PARCEL1', 'https://usps.example/9400PARCEL1');
    $this->actingAs($this->buyer)->get($url)->assertOk()
        ->assertSee('Partially shipped')
        ->assertSee('1 parcel shipped')
        ->assertSee('Parcel 1')->assertSee('9400PARCEL1')->assertSee('https://usps.example/9400PARCEL1', false)
        ->assertDontSee('Acme')
        ->assertDontSee('Confirm received');

    // the supplier's parcel too
    $po = purchaseOrderOf($order, $this->acme);
    $pos = app(PurchaseOrderService::class);
    $pos->send($this->admin, $po);
    $pos->markShipped($this->admin, $po->fresh(), 'UPS', '1ZSUPPLIER');

    $this->actingAs($this->buyer)->get($url)->assertOk()
        ->assertSee('Parcel 1')->assertSee('Parcel 2')->assertSee('1ZSUPPLIER')->assertSee('Dropshipped Tweeter 25')
        ->assertSee('Shipped')
        ->assertSee('Confirm received')
        ->assertDontSee('Acme')->assertDontSee($po->number);
});

it('keeps showing the single tracking line for an order with one parcel', function () {
    $listing = sellableListing(onboardedSeller(), 5000, ['quantity' => 3]);
    $order = paidOrderOf($this->buyer, [[$listing, 1]]);
    app(ShipmentService::class)->markShipped($listing->seller, $order->sellerOrders->first(), 'UPS', '1ZONE', 'https://ups.example/1ZONE');

    $this->actingAs($this->buyer)->get(route('dashboard.orders.show', ['order' => $order->number]))->assertOk()
        ->assertSee('Shipped')->assertSee('UPS')->assertSee('1ZONE')->assertSee('Track parcel')
        ->assertDontSee('Parcel 1');
});
