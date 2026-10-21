<?php

use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Models\Design;
use App\Models\Driver;
use App\Models\User;
use App\Services\Marketplace\ShipmentService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('private');
    config(['marketplace.physical_payout_trigger' => 'shipped']);
    $this->buyer = User::factory()->create();
});

$dashboardUrls = [
    'library' => '/dashboard/library',
    'orders' => '/dashboard/orders',
    'order detail' => '/dashboard/orders/SDL-NOPE12',
    'selling' => '/dashboard/selling',
    'sales' => '/dashboard/selling/orders',
    'listings' => '/dashboard/listings',
    'addresses' => '/settings/addresses',
    'cart' => '/cart',
    'checkout' => '/checkout',
];

it('keeps guests out of every marketplace dashboard page', function (string $url) {
    $this->get($url)->assertRedirectContains('login');
})->with(array_diff_key($dashboardUrls, ['order detail' => true]));

it('opens every dashboard page for a signed-in user', function (string $url) {
    if (str_contains($url, 'SDL-NOPE12')) {
        $this->actingAs($this->buyer)->get($url)->assertNotFound();

        return;
    }

    $this->actingAs($this->buyer)->get($url)->assertOk();
})->with($dashboardUrls);

it('shows the Marketplace group in the dashboard sidebar', function () {
    $this->actingAs($this->buyer)->get('/dashboard/library')
        ->assertOk()
        ->assertSee('Marketplace')
        ->assertSee(route('dashboard.orders'), false)
        ->assertSee(route('dashboard.listings'), false)
        ->assertSee(route('selling.orders'), false)
        ->assertSee(route('selling.dashboard'), false)
        ->assertSee(route('dashboard.library'), false);
});

// --- library --------------------------------------------------------------------------------------------

it('lists the library with downloads for designs and the way they were obtained', function () {
    $design = paidDesign(onboardedSeller(), 1500);
    $free = Design::factory()->free()->withSnapshot('v1.0', true)->create(['name' => 'Free Library Design']);
    $revoked = Design::factory()->withSnapshot()->create(['name' => 'Revoked Library Design']);
    $this->buyer->grantEntitlement($design);
    $this->buyer->grantEntitlement($free, App\Enums\EntitlementSource::FREE);
    $this->buyer->grantEntitlement($revoked)->revoke();
    User::factory()->create()->grantEntitlement(Design::factory()->withSnapshot()->create(['name' => 'Somebody Elses Design']));

    $this->actingAs($this->buyer)->get('/dashboard/library')
        ->assertOk()
        ->assertSee($design->name)->assertSee(route('design-snapshots.download', $design->snapshots->first()), false)
        ->assertSee('Purchased')
        ->assertSee('Free Library Design')->assertSee('Free download')
        ->assertDontSee('Revoked Library Design')
        ->assertDontSee('Somebody Elses Design');
});

it('shows an empty library with a pointer to the catalogue', function () {
    $this->actingAs($this->buyer)->get('/dashboard/library')->assertOk()->assertSee('Your library is empty')->assertSee(route('designs'), false);
});

// --- orders -----------------------------------------------------------------------------------------------

it('lists only the buyers own orders with the status of every seller part', function () {
    $mine = paidListingOrder($this->buyer);
    $other = paidListingOrder(User::factory()->create());

    $this->actingAs($this->buyer)->get('/dashboard/orders')
        ->assertOk()
        ->assertSee($mine['order']->number)
        ->assertSee($mine['listing']->title)
        ->assertSee('Awaiting shipment')
        ->assertSee('Waiting for the seller to ship')
        ->assertSee(money($mine['order']->total_cents))
        ->assertSee(route('dashboard.orders.show', ['order' => $mine['order']->number]), false)
        ->assertDontSee($other['order']->number);
});

it('shows tracking and lets the buyer confirm receipt once the parcel shipped', function () {
    $x = paidListingOrder($this->buyer);
    $so = $x['sellerOrder'];

    $this->actingAs($this->buyer)->get('/dashboard/orders')->assertDontSee('Confirm received');

    app(ShipmentService::class)->markShipped($x['seller'], $so, 'UPS', '1Z999AA10123', 'https://ups.example/track/1Z999AA10123');

    $this->actingAs($this->buyer)->get('/dashboard/orders')
        ->assertOk()
        ->assertSee('Shipped')
        ->assertSee('UPS')->assertSee('1Z999AA10123')
        ->assertSee('https://ups.example/track/1Z999AA10123', false)->assertSee('Track parcel')
        ->assertSee('Confirm received')
        ->assertSee('action="'.route('orders.received', $so).'"', false);

    $this->actingAs($this->buyer)->post(route('orders.received', $so))->assertRedirect()->assertSessionHas('success');

    expect($so->fresh()->status)->toBe(SellerOrderStatus::COMPLETED);
    $this->actingAs($this->buyer)->get('/dashboard/orders')->assertDontSee('Confirm received')->assertSee('Completed');
});

it('does not let anybody but the buyer confirm a parcel', function () {
    $x = paidListingOrder($this->buyer);
    app(ShipmentService::class)->markShipped($x['seller'], $x['sellerOrder'], 'DHL', 'TRACK1');

    $this->actingAs(User::factory()->create())->post(route('orders.received', $x['sellerOrder']))->assertForbidden();
    $this->actingAs($x['seller'])->post(route('orders.received', $x['sellerOrder']))->assertForbidden();
    expect($x['sellerOrder']->fresh()->status)->toBe(SellerOrderStatus::SHIPPED);
});

// --- order detail ---------------------------------------------------------------------------------------------

it('shows the buyer an order with items, totals, address and sellers', function () {
    $x = paidListingOrder($this->buyer, null, 6000);
    $order = $x['order'];

    $this->actingAs($this->buyer)->get(route('dashboard.orders.show', ['order' => $order->number]))
        ->assertOk()
        ->assertSee('Order '.$order->number)
        ->assertSee($x['listing']->title)
        ->assertSee('Payment')->assertSee(money($order->total_cents))
        ->assertSee('Ship to')->assertSee($order->shipping_address['line1'])
        ->assertSee($x['seller']->sellerProfile->shop_name);
});

it('protects the order detail: another buyer gets 403, guests are sent to login, unknown numbers are 404', function () {
    $order = paidListingOrder($this->buyer)['order'];
    $url = route('dashboard.orders.show', ['order' => $order->number]);

    // the guest request first: Livewire swaps the redirector of the app once it has rendered a page
    $this->get($url)->assertRedirectContains('login');
    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    $this->actingAs($this->buyer)->get('/dashboard/orders/SDL-ZZZZZZ')->assertNotFound();
});

it('shows a seller only their own part of an order, and admins everything', function () {
    $sellerA = onboardedSeller();
    $sellerB = onboardedSeller();
    $a = sellableListing($sellerA, 4000, ['title' => 'Part Of Seller A']);
    $b = sellableListing($sellerB, 5000, ['title' => 'Part Of Seller B']);
    $order = pendingOrder($this->buyer, [$a, $b], addressFor($this->buyer));
    payOrder($order, 'pi_two', 'ch_two');
    $url = route('dashboard.orders.show', ['order' => $order->number]);

    $this->actingAs($sellerA)->get($url)->assertOk()->assertSee('Part Of Seller A')->assertDontSee('Part Of Seller B')->assertDontSee('Payment');
    $this->actingAs(adminUser())->get($url)->assertOk()->assertSee('Part Of Seller A')->assertSee('Part Of Seller B')->assertSee('Payment');
    $this->actingAs(onboardedSeller())->get($url)->assertForbidden();
});

it('only offers the confirm button to the buyer on the order detail', function () {
    $x = paidListingOrder($this->buyer);
    app(ShipmentService::class)->markShipped($x['seller'], $x['sellerOrder'], 'FedEx', 'FX1');
    $url = route('dashboard.orders.show', ['order' => $x['order']->number]);

    $this->actingAs($this->buyer)->get($url)->assertSee('Confirm received');
    $this->actingAs($x['seller'])->get($url)->assertOk()->assertDontSee('Confirm received')->assertSee('FX1');
});

it('shows refunded parts of an order as refunded', function () {
    $design = paidDesign(onboardedSeller(), 2500);
    $order = pendingOrder($this->buyer, [$design]);
    payOrder($order, 'pi_ref', 'ch_ref');
    app(App\Services\Marketplace\RefundService::class)->refundOrder(adminUser(), $order->fresh(), 'test');

    expect($order->fresh()->status)->toBe(OrderStatus::REFUNDED);
    $this->actingAs($this->buyer)->get(route('dashboard.orders.show', ['order' => $order->number]))
        ->assertOk()->assertSee('Refunded')->assertSee('$25.00');
    // revoked access: the item is gone from the library
    $this->actingAs($this->buyer)->get('/dashboard/library')->assertDontSee($design->name);
});
