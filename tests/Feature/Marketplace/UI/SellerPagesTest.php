<?php

use App\Enums\SellerOrderStatus;
use App\Models\SellerProfile;
use App\Models\User;
use App\Notifications\OrderShipped;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    config(['marketplace.fee_bps' => 1000, 'marketplace.fee_fixed_cents' => 0, 'marketplace.physical_payout_trigger' => 'shipped']);
});

// --- Stripe card -------------------------------------------------------------------------------------

it('shows the Stripe card for every stage of the onboarding', function () {
    $new = User::factory()->create();
    $this->actingAs($new)->get('/dashboard/selling')
        ->assertOk()->assertSee('data-stripe-state="not_started"', false)->assertSee('Not started')
        ->assertSee('Connect Stripe')->assertSee(route('selling.connect'), false);

    $incomplete = User::factory()->seller(['stripe_account_id' => 'acct_inc', 'details_submitted' => false, 'charges_enabled' => false, 'payouts_enabled' => false])->create();
    $this->actingAs($incomplete)->get('/dashboard/selling')
        ->assertSee('data-stripe-state="incomplete"', false)->assertSee('Continue Stripe setup')->assertSee(route('selling.connect'), false);

    $pending = User::factory()->seller(['stripe_account_id' => 'acct_pen', 'details_submitted' => true, 'charges_enabled' => false, 'payouts_enabled' => false])->create();
    $this->actingAs($pending)->get('/dashboard/selling')
        ->assertSee('data-stripe-state="pending"', false)->assertSee('Pending verification')->assertSee('Refresh status')->assertSee(route('selling.dashboard-link'), false);

    $this->actingAs(onboardedSeller())->get('/dashboard/selling')
        ->assertSee('data-stripe-state="active"', false)->assertSee('Active')->assertSee('Open Stripe dashboard')->assertSee(route('selling.dashboard-link'), false)
        ->assertDontSee('Connect Stripe');

    $this->actingAs(adminUser())->get('/dashboard/selling')
        ->assertSee('data-stripe-state="platform"', false)->assertSee('Platform seller')->assertDontSee('Connect Stripe');
});

it('asks Stripe for the current state when the seller presses "Refresh status"', function () {
    $seller = User::factory()->seller(['stripe_account_id' => 'acct_wait', 'details_submitted' => true, 'charges_enabled' => false, 'payouts_enabled' => false])->create();
    $page = voltPage($this, $seller, '/dashboard/selling', 'selling.dashboard');

    fakeGateway()->setAccountStatus('acct_wait', false, false, true);
    $page->call('refreshStripe')->assertNotified('Stripe is still verifying your details');
    expect($seller->sellerProfile->fresh()->canReceivePayouts())->toBeFalse();

    fakeGateway()->setAccountStatus('acct_wait', true, true, true);
    $page->call('refreshStripe')->assertNotified('Payouts are set up');
    expect($seller->sellerProfile->fresh()->canReceivePayouts())->toBeTrue();
});

// --- stats ------------------------------------------------------------------------------------------------

it('shows gross sales, fees, payouts and pending payouts, and the recent sales', function () {
    $buyer = User::factory()->create();
    $x = paidListingOrder($buyer, null, 6000, ['title' => 'Stats Woofer']);   // 6000 items + 900 shipping, fee 600
    $seller = $x['seller'];

    $page = $this->actingAs($seller)->get('/dashboard/selling')->assertOk();
    $page->assertSee('$69.00')                 // gross
        ->assertSee('$6.00')                   // platform fee
        ->assertSee('$63.00')                  // pending payout: not shipped yet
        ->assertSee('Stats Woofer')->assertSee($x['order']->number)->assertSee('Awaiting shipment')
        ->assertSee('1 to ship');

    app(App\Services\Marketplace\ShipmentService::class)->markShipped($seller, $x['sellerOrder'], 'UPS', '1Z1');

    $this->actingAs($seller)->get('/dashboard/selling')
        ->assertSeeHtml('data-stat="payouts"')->assertSee('Shipped')
        ->assertSeeInOrder(['Paid out', '$63.00']);
    expect($x['sellerOrder']->fresh()->isTransferred())->toBeTrue();
});

it('counts only the sellers own sales', function () {
    $buyer = User::factory()->create();
    paidListingOrder($buyer, null, 6000);
    $other = onboardedSeller();

    $this->actingAs($other)->get('/dashboard/selling')->assertOk()->assertSee('No sales yet')->assertDontSee('$69.00');
});

// --- shop profile --------------------------------------------------------------------------------------

it('creates the shop profile the first time it is saved and builds the slug from the name', function () {
    $user = User::factory()->create();

    voltPage($this, $user, '/dashboard/selling', 'selling.dashboard')
        ->set('data.shop_name', 'Treble & Bass Shop')
        ->set('data.slug', '')
        ->set('data.bio', 'Used and new drivers.')
        ->set('data.country', 'DE')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNotified('Shop profile saved');

    $profile = $user->sellerProfile()->firstOrFail();
    expect($profile->shop_name)->toBe('Treble & Bass Shop')
        ->and($profile->slug)->toBe('treble-bass-shop')
        ->and($profile->bio)->toBe('Used and new drivers.')
        ->and($profile->country)->toBe('DE')
        ->and($profile->stripe_account_id)->toBeNull();

    $this->get(route('seller', ['slug' => 'treble-bass-shop']))->assertOk()->assertSee('Treble &amp; Bass Shop', false);
});

it('updates the profile, validates the slug and fixes the country once Stripe has the account', function () {
    $seller = onboardedSeller();
    $seller->sellerProfile->update(['country' => 'US', 'slug' => 'my-shop']);
    SellerProfile::factory()->create(['slug' => 'taken-slug']);
    $page = voltPage($this, $seller, '/dashboard/selling', 'selling.dashboard');

    $page->set('data.shop_name', '')->call('save')->assertHasErrors(['data.shop_name' => 'required']);
    $page->set('data.shop_name', 'Renamed')->set('data.slug', 'taken-slug')->call('save')->assertHasErrors(['data.slug']);
    $page->set('data.slug', 'bad slug!')->call('save')->assertHasErrors(['data.slug']);

    $page->set('data.shop_name', 'Renamed')->set('data.slug', 'renamed-shop')->set('data.bio', 'New bio')->set('data.country', 'FR')->call('save')->assertHasNoErrors();

    $profile = $seller->sellerProfile->fresh();
    expect($profile->shop_name)->toBe('Renamed')->and($profile->slug)->toBe('renamed-shop')->and($profile->bio)->toBe('New bio')
        ->and($profile->country)->toBe('US'); // Stripe fixed it when the account was created
});

// --- sales / ship form ------------------------------------------------------------------------------------

it('shows a seller only their own sales', function () {
    $buyer = User::factory()->create();
    $mine = paidListingOrder($buyer);
    $theirs = paidListingOrder($buyer);
    $unpaid = pendingOrder($buyer, [sellableListing($mine['seller'])], addressFor($buyer)); // pending: not a sale yet

    voltPage($this, $mine['seller'], '/dashboard/selling/orders', 'selling.orders')
        ->assertCanSeeTableRecords([$mine['sellerOrder']])
        ->assertCanNotSeeTableRecords([$theirs['sellerOrder']])
        ->assertCountTableRecords(1);

    $this->actingAs($mine['seller'])->get('/dashboard/selling/orders')->assertOk()->assertSee($mine['order']->number)->assertDontSee($theirs['order']->number);
});

it('ships an order with carrier, tracking number and link, and tells the buyer', function () {
    Illuminate\Support\Facades\Notification::fake();
    $buyer = User::factory()->create();
    $x = paidListingOrder($buyer);

    voltPage($this, $x['seller'], '/dashboard/selling/orders', 'selling.orders')
        ->assertTableActionVisible('ship', $x['sellerOrder'])
        ->callTableAction('ship', $x['sellerOrder'], data: ['carrier' => 'UPS', 'tracking_number' => '1Z999AA1', 'tracking_url' => 'https://ups.example/1Z999AA1'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Marked as shipped');

    $so = $x['sellerOrder']->fresh();
    expect($so->status)->toBe(SellerOrderStatus::SHIPPED)
        ->and($so->carrier)->toBe('UPS')->and($so->tracking_number)->toBe('1Z999AA1')->and($so->tracking_url)->toBe('https://ups.example/1Z999AA1')
        ->and($so->isTransferred())->toBeTrue();
    Illuminate\Support\Facades\Notification::assertSentTo($buyer, OrderShipped::class);
});

it('validates the ship form', function () {
    $x = paidListingOrder(User::factory()->create());

    $page = voltPage($this, $x['seller'], '/dashboard/selling/orders', 'selling.orders');
    $page->callTableAction('ship', $x['sellerOrder'], data: ['carrier' => '', 'tracking_number' => ''])
        ->assertHasTableActionErrors(['carrier' => 'required', 'tracking_number' => 'required']);
    $page->callTableAction('ship', $x['sellerOrder'], data: ['carrier' => 'DHL', 'tracking_number' => '1', 'tracking_url' => 'not a url'])
        ->assertHasTableActionErrors(['tracking_url']);

    expect($x['sellerOrder']->fresh()->status)->toBe(SellerOrderStatus::AWAITING_SHIPMENT);
});

it('lets the seller correct the tracking of a shipped order, but not ship digital or finished orders', function () {
    $buyer = User::factory()->create();
    $x = paidListingOrder($buyer);
    $digital = pendingOrder($buyer, [paidDesign($x['seller'], 1000)]);
    payOrder($digital, 'pi_dig', 'ch_dig');
    $digitalSo = $digital->sellerOrders()->first();

    $page = voltPage($this, $x['seller'], '/dashboard/selling/orders', 'selling.orders');
    $page->callTableAction('ship', $x['sellerOrder'], data: ['carrier' => 'UPS', 'tracking_number' => 'OLD1']);
    $page->assertTableActionVisible('ship', $x['sellerOrder'])
        ->callTableAction('ship', $x['sellerOrder'], data: ['carrier' => 'UPS', 'tracking_number' => 'NEW1']);
    expect($x['sellerOrder']->fresh()->tracking_number)->toBe('NEW1');

    $page->assertTableActionHidden('ship', $digitalSo); // completed digital order: nothing to ship
    app(App\Services\Marketplace\ShipmentService::class)->confirmReceived($buyer, $x['sellerOrder']->fresh());
    $page->assertTableActionHidden('ship', $x['sellerOrder']->fresh());
});

it('filters the sales by status, shipping need and type', function () {
    $buyer = User::factory()->create();
    $seller = onboardedSeller();
    $toShip = paidListingOrder($buyer, $seller);
    $shipped = paidListingOrder($buyer, $seller);
    app(App\Services\Marketplace\ShipmentService::class)->markShipped($seller, $shipped['sellerOrder'], 'UPS', 'T1');
    $digital = pendingOrder($buyer, [paidDesign($seller, 1200)]);
    payOrder($digital, 'pi_d2', 'ch_d2');
    $digitalSo = $digital->sellerOrders()->first();

    $page = voltPage($this, $seller, '/dashboard/selling/orders', 'selling.orders');
    $page->assertCountTableRecords(3)
        ->filterTable('to_ship', true)->assertCanSeeTableRecords([$toShip['sellerOrder']])->assertCanNotSeeTableRecords([$shipped['sellerOrder'], $digitalSo])
        ->resetTableFilters()
        ->filterTable('status', [SellerOrderStatus::SHIPPED->value])->assertCanSeeTableRecords([$shipped['sellerOrder']])->assertCountTableRecords(1)
        ->resetTableFilters()
        ->filterTable('type', 'digital')->assertCanSeeTableRecords([$digitalSo])->assertCountTableRecords(1)
        ->resetTableFilters()
        ->filterTable('type', 'physical')->assertCountTableRecords(2);
});

it('shows the order details with the buyers address in a modal', function () {
    $x = paidListingOrder(User::factory()->create());

    voltPage($this, $x['seller'], '/dashboard/selling/orders', 'selling.orders')
        ->mountTableAction('details', $x['sellerOrder'])
        ->assertSee('Order '.$x['order']->number)
        ->assertSee($x['order']->shipping_address['line1'])
        ->assertSee('Your payout');
});
