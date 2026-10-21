<?php

use App\Enums\EntitlementSource;
use App\Enums\ListingStatus;
use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Filament\Resources\DesignResource;
use App\Filament\Resources\DriverResource;
use App\Filament\Resources\EntitlementResource;
use App\Filament\Resources\ListingResource;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\Resources\OrderResource\RelationManagers\SellerOrdersRelationManager;
use App\Filament\Resources\SellerProfileResource;
use App\Filament\Resources\SellerProfileResource\Pages\EditSellerProfile;
use App\Filament\Resources\SellerProfileResource\Pages\ListSellerProfiles;
use App\Filament\Resources\StripeEventResource;
use App\Filament\Resources\StripeEventResource\Pages\ListStripeEvents;
use App\Filament\Widgets\MarketplaceStats;
use App\Models\Design;
use App\Models\Driver;
use App\Models\Entitlement;
use App\Models\Listing;
use App\Models\Order;
use App\Models\SellerProfile;
use App\Models\StripeEvent;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    config(['marketplace.fee_bps' => 1000, 'marketplace.physical_payout_trigger' => 'shipped']);
    $this->admin = adminUser();
    $this->buyer = User::factory()->create();
});

$adminUrls = [
    'orders' => '/admin/orders',
    'listings' => '/admin/listings',
    'new listing' => '/admin/listings/create',
    'seller profiles' => '/admin/seller-profiles',
    'entitlements' => '/admin/entitlements',
    'stripe events' => '/admin/stripe-events',
    'dashboard' => '/admin',
];

// --- access ---------------------------------------------------------------------------------------------------

it('opens every marketplace admin page for admins', function (string $url) {
    $this->actingAs($this->admin)->get($url)->assertOk();
})->with($adminUrls);

it('keeps regular users out of the marketplace admin pages', function (string $url) {
    $this->actingAs($this->buyer)->get($url)->assertForbidden();
})->with($adminUrls);

it('sends guests to the login', function (string $url) {
    $this->get($url)->assertRedirect();
})->with($adminUrls);

it('puts the marketplace resources in one navigation group', function () {
    foreach ([OrderResource::class, ListingResource::class, SellerProfileResource::class, EntitlementResource::class, StripeEventResource::class] as $resource) {
        expect($resource::getNavigationGroup())->toBe('Marketplace');
    }

    $this->actingAs($this->admin)->get('/admin/orders')->assertSee('Marketplace')->assertSee('Seller Profiles')->assertSee('Entitlements')->assertSee('Stripe Events');
});

it('lets non-admins neither see nor refund orders through the policy', function () {
    $order = paidListingOrder($this->buyer)['order'];

    expect($this->buyer->can('refund', $order))->toBeFalse()
        ->and($this->buyer->can('viewAny', Order::class))->toBeFalse()
        ->and($this->admin->can('refund', $order))->toBeTrue();
});

// --- dashboard widget -------------------------------------------------------------------------------------------

it('shows the marketplace numbers on the admin dashboard', function () {
    $since = now()->subDays(30);
    $paid = fn () => Order::query()->whereNotNull('paid_at')->whereNotIn('status', ['cancelled', 'pending']);
    $before = [
        'today' => (clone $paid())->where('paid_at', '>=', now()->startOfDay())->count(),
        'gmv' => (int) (clone $paid())->where('paid_at', '>=', $since)->sum('total_cents'),
        'fees' => (int) (clone $paid())->where('paid_at', '>=', $since)->sum('platform_fee_cents'),
    ];

    $x = paidListingOrder($this->buyer, null, 6000);
    $order = $x['order'];

    Livewire::actingAs($this->admin)->test(MarketplaceStats::class)
        ->assertSee('Orders today')->assertSee((string) ($before['today'] + 1))
        ->assertSee('GMV, 30 days')->assertSee(money($before['gmv'] + $order->total_cents))
        ->assertSee('Platform fees, 30 days')->assertSee(money($before['fees'] + $order->platform_fee_cents))
        ->assertSee('Pending payouts');

    // the widget is lazy: the dashboard carries its Livewire placeholder
    $this->actingAs($this->admin)->get('/admin')->assertOk()->assertSee('marketplace-stats', false);
});

// --- orders -------------------------------------------------------------------------------------------------------

it('lists, searches and filters orders', function () {
    $paid = paidListingOrder($this->buyer)['order'];
    $other = User::factory()->create(['email' => 'unique-buyer@example.test']);
    $pending = pendingOrder($other, [sellableListing()], addressFor($other));

    Livewire::actingAs($this->admin)->test(ListOrders::class)
        ->assertCanSeeTableRecords([$paid, $pending])
        ->searchTable($paid->number)->assertCanSeeTableRecords([$paid])->assertCanNotSeeTableRecords([$pending])
        ->searchTable('unique-buyer@example.test')->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$paid])
        ->searchTable(null)
        ->filterTable('status', [OrderStatus::PENDING->value])->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$paid])
        ->resetTableFilters()
        ->filterTable('paid', true)->assertCanSeeTableRecords([$paid])->assertCanNotSeeTableRecords([$pending]);
});

it('shows an order with buyer, address, items and totals, and no way to edit or delete it', function () {
    $x = paidListingOrder($this->buyer, null, 6000, ['title' => 'Admin View Woofer']);
    $order = $x['order'];

    $this->actingAs($this->admin)->get('/admin/orders/'.$order->number)
        ->assertOk()
        ->assertSee($order->number)
        ->assertSee($this->buyer->name)
        ->assertSee($order->shipping_address['line1'])
        ->assertSee('Admin View Woofer')
        ->assertSee(money($order->total_cents))
        ->assertSee('Refund order');

    expect(OrderResource::canCreate())->toBeFalse()
        ->and(OrderResource::canEdit($order))->toBeFalse()
        ->and(OrderResource::canDelete($order))->toBeFalse();
    $this->actingAs($this->admin)->get('/admin/orders/'.$order->number.'/edit')->assertNotFound();
});

it('refunds a whole order through RefundService with the admin as actor', function () {
    $x = paidListingOrder($this->buyer, null, 6000);
    $order = $x['order'];

    Livewire::actingAs($this->admin)->test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertActionVisible('refundOrder')
        ->callAction('refundOrder', data: ['reason' => 'Damaged in transit'])
        ->assertHasNoActionErrors()
        ->assertNotified('Refunded '.money($order->total_cents));

    $refund = fakeGateway()->lastCall('refund');
    expect(fakeGateway()->callCount('refund'))->toBe(1)
        ->and($refund['meta']['reason'])->toBe('Damaged in transit')
        ->and($refund['meta']['refunded_by'])->toBe($this->admin->getKey())
        ->and($order->fresh()->status)->toBe(OrderStatus::REFUNDED)
        ->and($x['sellerOrder']->fresh()->status)->toBe(SellerOrderStatus::REFUNDED);

    // nothing left to give back: the action disappears
    Livewire::actingAs($this->admin)->test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertActionHidden('refundOrder');
});

it('does not offer a refund for an order that was never paid', function () {
    $pending = pendingOrder($this->buyer, [sellableListing()], addressFor($this->buyer));

    Livewire::actingAs($this->admin)->test(ViewOrder::class, ['record' => $pending->getRouteKey()])->assertActionHidden('refundOrder');
});

it('tells the admin when Stripe refuses a refund and changes nothing', function () {
    $x = paidListingOrder($this->buyer);
    fakeGateway()->failOn('refund');

    Livewire::actingAs($this->admin)->test(ViewOrder::class, ['record' => $x['order']->getRouteKey()])
        ->callAction('refundOrder')
        ->assertNotified('Stripe refused the refund');

    expect($x['order']->fresh()->status)->not->toBe(OrderStatus::REFUNDED)
        ->and($x['sellerOrder']->fresh()->status)->toBe(SellerOrderStatus::AWAITING_SHIPMENT);
});

it('refunds one sellers part of an order from the seller orders relation manager', function () {
    $digital = paidDesign(onboardedSeller(), 3000);
    $listing = sellableListing(onboardedSeller(), 6000, ['shipping_domestic_cents' => 1000]);
    $order = pendingOrder($this->buyer, [$digital, $listing], addressFor($this->buyer));
    payOrder($order, 'pi_two_part', 'ch_two_part');
    $designPart = $order->sellerOrders()->where('seller_id', $digital->user_id)->first();
    $listingPart = $order->sellerOrders()->where('seller_id', $listing->seller_id)->first();

    $manager = Livewire::actingAs($this->admin)->test(SellerOrdersRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
        ->assertCanSeeTableRecords([$designPart, $listingPart])
        ->assertTableActionVisible('refundSellerOrder', $designPart)
        ->callTableAction('refundSellerOrder', $designPart, data: ['reason' => 'Wrong file'])
        ->assertNotified('Refunded '.money(3000));

    expect($designPart->fresh()->status)->toBe(SellerOrderStatus::REFUNDED)
        ->and($listingPart->fresh()->status)->toBe(SellerOrderStatus::AWAITING_SHIPMENT)
        ->and($order->fresh()->status)->toBe(OrderStatus::PARTIALLY_REFUNDED)
        ->and(fakeGateway()->lastCall('refund')['amountCents'])->toBe(3000)
        ->and($this->buyer->hasAccessTo($digital, false))->toBeFalse();

    $manager->assertTableActionHidden('refundSellerOrder', $designPart->fresh());
});

it('flags a payout that could not be taken back and retries the reversal', function () {
    $design = paidDesign(onboardedSeller(), 3000);
    $order = pendingOrder($this->buyer, [$design]);
    payOrder($order, 'pi_rev', 'ch_rev');
    $part = $order->sellerOrders()->firstOrFail();
    expect($part->isTransferred())->toBeTrue();

    fakeGateway()->failOn('reverseTransfer');
    $manager = Livewire::actingAs($this->admin)->test(SellerOrdersRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class]);
    $manager->callTableAction('refundSellerOrder', $part)
        ->assertNotified('A payout could not be taken back');

    expect($part->fresh()->stripe_reversal_id)->toBeNull();
    $manager->assertSee('payout reversal pending')->assertTableActionVisible('retryReversal', $part->fresh());

    // the order list can be filtered to the orders that need it
    Livewire::actingAs($this->admin)->test(ListOrders::class)
        ->filterTable('reversal_pending', true)->assertCanSeeTableRecords([$order->fresh()]);

    $manager->callTableAction('retryReversal', $part->fresh())->assertNotified('Transfer reversed');

    expect($part->fresh()->stripe_reversal_id)->not->toBeNull();
    $manager->assertTableActionHidden('retryReversal', $part->fresh());
    Livewire::actingAs($this->admin)->test(ListOrders::class)->filterTable('reversal_pending', true)->assertCanNotSeeTableRecords([$order->fresh()]);
});

// --- seller profiles ----------------------------------------------------------------------------------------------

it('lets the admin change the commission but not the Stripe flags of a seller profile', function () {
    $profile = onboardedSeller()->sellerProfile;
    $profile->update(['charges_enabled' => true, 'payouts_enabled' => true, 'commission_bps' => null]);

    Livewire::actingAs($this->admin)->test(ListSellerProfiles::class)->assertCanSeeTableRecords([$profile]);

    $page = Livewire::actingAs($this->admin)->test(EditSellerProfile::class, ['record' => $profile->getRouteKey()])
        ->assertFormFieldIsDisabled('charges_enabled')
        ->assertFormFieldIsDisabled('payouts_enabled')
        ->assertFormFieldIsDisabled('details_submitted')
        ->assertFormFieldIsDisabled('stripe_account_id')
        ->assertFormFieldIsDisabled('shop_name');

    $page->fillForm(['commission_bps' => 750, 'charges_enabled' => false, 'payouts_enabled' => false])->call('save')->assertHasNoFormErrors();

    $profile->refresh();
    expect($profile->commission_bps)->toBe(750)->and($profile->charges_enabled)->toBeTrue()->and($profile->payouts_enabled)->toBeTrue();

    $page->fillForm(['commission_bps' => 20000])->call('save')->assertHasFormErrors(['commission_bps']);
    $page->fillForm(['commission_bps' => null])->call('save')->assertHasNoFormErrors();
    expect($profile->fresh()->commission_bps)->toBeNull();
});

it('refreshes the Stripe flags of a seller profile on demand', function () {
    $profile = User::factory()->seller(['stripe_account_id' => 'acct_adm', 'charges_enabled' => false, 'payouts_enabled' => false, 'details_submitted' => false])->create()->sellerProfile;
    fakeGateway()->setAccountStatus('acct_adm', true, true, true);

    Livewire::actingAs($this->admin)->test(ListSellerProfiles::class)
        ->assertTableActionVisible('refreshFromStripe', $profile)
        ->callTableAction('refreshFromStripe', $profile)
        ->assertNotified('Refreshed from Stripe');

    expect($profile->fresh()->canReceivePayouts())->toBeTrue()->and($profile->fresh()->details_submitted)->toBeTrue();

    $unconnected = SellerProfile::factory()->withoutStripe()->create();
    Livewire::actingAs($this->admin)->test(ListSellerProfiles::class)->assertTableActionHidden('refreshFromStripe', $unconnected);

    Livewire::actingAs($this->admin)->test(EditSellerProfile::class, ['record' => $profile->getRouteKey()])->assertActionExists('refreshFromStripe');
});

// --- entitlements ----------------------------------------------------------------------------------------------------

it('grants, revokes and restores access to designs', function () {
    $design = Design::factory()->withSnapshot()->create();
    $list = \App\Filament\Resources\EntitlementResource\Pages\ListEntitlements::class;

    Livewire::actingAs($this->admin)->test($list)
        ->callAction('grant', data: ['user_id' => $this->buyer->id, 'item_id' => $design->id])
        ->assertHasNoActionErrors()
        ->assertNotified('Access granted');

    $entitlement = $this->buyer->entitlementFor($design);
    expect($entitlement->isActive())->toBeTrue()->and($entitlement->source)->toBe(EntitlementSource::ADMIN_GRANT)
        ->and($this->buyer->hasAccessTo($design, false))->toBeTrue();

    $table = Livewire::actingAs($this->admin)->test($list)
        ->assertCanSeeTableRecords([$entitlement])
        ->assertTableActionVisible('revoke', $entitlement)->assertTableActionHidden('restore', $entitlement)
        ->callTableAction('revoke', $entitlement);

    expect($entitlement->fresh()->isActive())->toBeFalse();
    $table->assertTableActionHidden('revoke', $entitlement->fresh())->assertTableActionVisible('restore', $entitlement->fresh())
        ->callTableAction('restore', $entitlement->fresh());
    expect($entitlement->fresh()->isActive())->toBeTrue();

    $table->filterTable('source', EntitlementSource::ADMIN_GRANT->value)->assertCanSeeTableRecords([$entitlement]);
    expect(EntitlementResource::canCreate())->toBeFalse();
});

it('does not duplicate or downgrade an existing entitlement when granting again', function () {
    $design = paidDesign(onboardedSeller(), 1500);
    $this->buyer->grantEntitlement($design, EntitlementSource::PURCHASE);

    Livewire::actingAs($this->admin)->test(\App\Filament\Resources\EntitlementResource\Pages\ListEntitlements::class)
        ->callAction('grant', data: ['user_id' => $this->buyer->id, 'item_id' => $design->id]);

    expect(Entitlement::where('user_id', $this->buyer->id)->count())->toBe(1)
        ->and($this->buyer->entitlementFor($design)->source)->toBe(EntitlementSource::PURCHASE);
});

// --- Stripe events ------------------------------------------------------------------------------------------------------

it('lists the received Stripe events read-only', function () {
    $done = StripeEvent::factory()->create(['id' => 'evt_done', 'type' => 'checkout.session.completed', 'processed_at' => now()]);
    $open = StripeEvent::factory()->create(['id' => 'evt_open', 'type' => 'charge.refunded', 'processed_at' => null]);

    Livewire::actingAs($this->admin)->test(ListStripeEvents::class)
        ->assertCanSeeTableRecords([$done, $open])
        ->searchTable('evt_open')->assertCanSeeTableRecords([$open])->assertCanNotSeeTableRecords([$done])
        ->searchTable(null)
        ->filterTable('processed', false)->assertCanSeeTableRecords([$open])->assertCanNotSeeTableRecords([$done]);

    expect(StripeEventResource::canCreate())->toBeFalse()->and(StripeEventResource::canEdit($done))->toBeFalse()->and(StripeEventResource::canDelete($done))->toBeFalse();
    $this->actingAs($this->admin)->get('/admin/stripe-events/create')->assertNotFound();
});

// --- listings -----------------------------------------------------------------------------------------------------------

it('lists, filters and edits listings of every seller', function () {
    $a = sellableListing(attributes: ['title' => 'Zebra Alpha']);
    $b = Listing::factory()->used()->draft()->create(['title' => 'Zebra Bravo']);
    $gone = sellableListing(attributes: ['title' => 'Zebra Charlie']);
    $gone->delete();

    Livewire::actingAs($this->admin)->test(ListingResource\Pages\ListListings::class)
        ->assertCanSeeTableRecords([$a, $b])
        ->searchTable('Alpha')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
        ->searchTable(null)
        ->filterTable('status', ListingStatus::DRAFT->value)->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a])
        ->resetTableFilters()
        ->filterTable('trashed', true)->assertCanSeeTableRecords([$gone]);

    Livewire::actingAs($this->admin)->test(ListingResource\Pages\EditListing::class, ['record' => $a->getRouteKey()])
        ->assertFormSet(['title' => 'Zebra Alpha', 'price_cents' => number_format($a->price_cents / 100, 2, '.', '')])
        ->fillForm(['title' => 'Renamed by admin', 'price_cents' => '19.99'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($a->fresh()->title)->toBe('Renamed by admin')->and($a->fresh()->price_cents)->toBe(1999);
});

it('lets admins activate a listing that has open issues, with a warning', function () {
    $seller = User::factory()->create(); // no payout account
    $listing = Listing::factory()->forSeller($seller)->draft()->create();

    Livewire::actingAs($this->admin)->test(ListingResource\Pages\EditListing::class, ['record' => $listing->getRouteKey()])
        ->fillForm(['status' => ListingStatus::ACTIVE->value])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Activated despite open issues');

    expect($listing->fresh()->status)->toBe(ListingStatus::ACTIVE);

    $other = Listing::factory()->forSeller($seller)->draft()->create();
    Livewire::actingAs($this->admin)->test(ListingResource\Pages\ListListings::class)
        ->call('updateTableColumnState', 'status', $other->getKey(), ListingStatus::ACTIVE->value)
        ->assertNotified('Activated despite open issues');
    expect($other->fresh()->status)->toBe(ListingStatus::ACTIVE);

    // a clean listing is activated silently
    $clean = sellableListing()->forceFill(['status' => ListingStatus::PAUSED])->save();
});

it('creates a listing from the admin with a generated uuid', function () {
    $seller = onboardedSeller();

    Livewire::actingAs($this->admin)->test(ListingResource\Pages\CreateListing::class)
        ->fillForm([
            'seller_id' => $seller->id, 'title' => 'Created by admin', 'status' => 'draft', 'channel' => 'marketplace', 'category' => 'kit', 'condition' => 'new', 'price_cents' => '10.00',
            'quantity' => 1, 'units_per_lot' => 1, 'ships_from_country' => 'US', 'handling_days' => 3,
            'shipping_domestic_cents' => '0', 'additional_item_shipping_cents' => '0',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $listing = Listing::where('title', 'Created by admin')->firstOrFail();
    expect($listing->uuid)->toMatch('/^[0-9a-f-]{36}$/')->and($listing->seller_id)->toBe($seller->id)->and($listing->price_cents)->toBe(1000)
        ->and($listing->channel)->toBe(App\Enums\ListingChannel::MARKETPLACE)->and($listing->category)->toBe(App\Enums\ListingCategory::KIT);
});

it('creates store listings from the admin and refuses the ones that break the store rules', function () {
    $driver = Driver::factory()->create();
    $form = fn (array $overrides = []) => $overrides + [
        'seller_id' => $this->admin->id, 'title' => 'Store from admin', 'status' => 'active', 'channel' => 'store', 'category' => 'driver',
        'driver_id' => $driver->id, 'condition' => 'new', 'price_cents' => '129.00', 'quantity' => 12, 'units_per_lot' => 1,
        'ships_from_country' => 'US', 'handling_days' => 2, 'shipping_domestic_cents' => '8.99', 'additional_item_shipping_cents' => '2',
    ];
    $create = fn (array $overrides = []) => Livewire::actingAs($this->admin)->test(ListingResource\Pages\CreateListing::class)->fillForm($form($overrides))->call('create');

    $create()->assertHasNoFormErrors();
    $store = Listing::where('title', 'Store from admin')->firstOrFail();
    expect($store->channel)->toBe(App\Enums\ListingChannel::STORE)
        ->and($store->category)->toBe(App\Enums\ListingCategory::DRIVER)
        ->and($store->seller_id)->toBe($this->admin->id)
        ->and($driver->storeListing()?->is($store))->toBeTrue();

    // a second active store listing for the same driver
    $create(['title' => 'Duplicate store'])->assertNotified('This listing breaks a rule');
    // a seller who is not an admin
    $create(['title' => 'Store by a user', 'seller_id' => onboardedSeller()->id, 'status' => 'draft'])->assertNotified('This listing breaks a rule');
    // used condition
    $create(['title' => 'Used store', 'condition' => 'used_good', 'status' => 'draft'])->assertNotified('This listing breaks a rule');
    // used gear that claims to be new
    $create(['title' => 'Fake used', 'channel' => 'marketplace', 'category' => 'used_gear', 'status' => 'draft'])->assertNotified('This listing breaks a rule');

    expect(Listing::whereIn('title', ['Duplicate store', 'Store by a user', 'Used store', 'Fake used'])->count())->toBe(0);

    // editing rechecks the rules: the store listing cannot become used
    Livewire::actingAs($this->admin)->test(ListingResource\Pages\EditListing::class, ['record' => $store->getRouteKey()])
        ->fillForm(['condition' => 'used_good'])
        ->call('save')
        ->assertNotified('This listing breaks a rule');
    expect($store->fresh()->condition)->toBe(App\Enums\ListingCondition::NEW);

    // the table shows and filters by channel
    $market = sellableListing();
    Livewire::actingAs($this->admin)->test(ListingResource\Pages\ListListings::class)
        ->filterTable('channel', 'store')->assertCanSeeTableRecords([$store])->assertCanNotSeeTableRecords([$market]);
});

// --- design / driver activation -----------------------------------------------------------------------------------------

it('warns admins who publish a design that cannot be sold as it is', function () {
    $unpaidSeller = User::factory()->create();
    $design = Design::factory()->for($unpaidSeller, 'designer')->paid(1000)->draft()->create();
    $free = Design::factory()->draft()->create();

    Livewire::actingAs($this->admin)->test(DesignResource\Pages\ListDesigns::class)
        ->call('updateTableColumnState', 'active', $design->getKey(), true)
        ->assertNotified('Published despite open issues');
    expect($design->fresh()->active)->toBeTrue();

    Livewire::actingAs($this->admin)->test(DesignResource\Pages\ListDesigns::class)
        ->call('updateTableColumnState', 'active', $free->getKey(), true)
        ->assertNotNotified('Published despite open issues');

    // drivers carry no price any more: publishing one is never a problem
    $driver = Driver::factory()->draft()->create();
    Livewire::actingAs($this->admin)->test(DriverResource\Pages\ListDrivers::class)
        ->call('updateTableColumnState', 'active', $driver->getKey(), true)
        ->assertNotNotified('Published despite open issues');
    expect($driver->fresh()->active)->toBeTrue();
});
