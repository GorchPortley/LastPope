<?php

use App\Models\StripeBalance;
use App\Models\User;
use App\Services\Marketplace\Ledger\SellerEarnings;
use App\Services\Marketplace\RefundService;
use App\Services\Marketplace\ShipmentService;
use Illuminate\Support\Facades\Notification;

/*
| R6.2 seller: the Earnings page of the selling dashboard - Stripe balance (available, pending, on its way to the bank), payouts to
| their bank, transfers from the site linked to their orders, and the money the site still holds - for the seller's own account only.
*/

beforeEach(function () {
    Notification::fake();
    config(['marketplace.fee_bps' => 1000, 'marketplace.physical_payout_trigger' => 'shipped', 'marketplace.stripe.secret_key' => 'sk_test_ledger']);
    $this->url = '/dashboard/selling/earnings';
    $this->buyer = User::factory()->create();
});

/**
 * A seller with an order shipped three days ago (6300 transferred, in their balance by now), one shipped today (6300 transferred, pending),
 * one still waiting for the parcel (6300 held by the site), a payout of 1000 on its way and a failed one of 2000. Everything synced.
 */
function earningSeller(object $test, int $inTransit = 1000, int $failed = 2000): array
{
    $seller = onboardedSeller();
    $paidOut = chargedListingOrder($test->buyer, $seller);
    app(ShipmentService::class)->markShipped($seller, $paidOut['sellerOrder'], 'UPS', '1Z1', null);
    $test->travel(3)->days();
    $recent = chargedListingOrder($test->buyer, $seller);
    app(ShipmentService::class)->markShipped($seller, $recent['sellerOrder'], 'UPS', '1Z2', null);
    $waiting = chargedListingOrder($test->buyer, $seller);
    $account = stripeAccountOf($seller);
    fakeGateway()->recordPayout($account, $inTransit, ['status' => 'in_transit', 'id' => 'po_'.$account.'_a']);
    fakeGateway()->recordPayout($account, $failed, ['status' => 'failed', 'failure_code' => 'incorrect_account_holder_name', 'id' => 'po_'.$account.'_b', 'created' => now()->subDay()]);
    syncAllLedgers();

    return ['seller' => $seller, 'account' => $account, 'paidOut' => $paidOut, 'recent' => $recent, 'waiting' => $waiting];
}

it('needs a login', function () {
    $this->get($this->url)->assertRedirect();
});

it('shows the seller their balance, payouts, transfers and what the site still holds', function () {
    $x = earningSeller($this);

    $response = $this->actingAs($x['seller'])->get($this->url)->assertOk();
    $response
        ->assertSeeHtml('data-earnings-card="available"')->assertSeeHtml('data-earnings-card="pending"')->assertSeeHtml('data-earnings-card="in-transit"')->assertSeeHtml('data-earnings-card="holding"')
        ->assertSee('Available')->assertSee('Pending')->assertSee('On its way to your bank')->assertSee('Held by '.config('app.name'));

    // the first transfer of 6300 is in the balance by now, less the payout of 1000 on its way (the failed one came back); today's transfer is pending
    $balance = StripeBalance::forAccount($x['account'])->firstOrFail();
    expect($balance->available_cents)->toBe(6300 - 1000)->and($balance->pending_cents)->toBe(6300)->and($balance->in_transit_cents)->toBe(1000);
    $response->assertSeeInOrder(['Available', '$53.00', 'Pending', '$63.00', 'On its way to your bank', '$10.00', 'Held by', '$63.00']);

    // payouts: the one on its way, and the failed one with its reason in plain words
    $response->assertSee('STRIPE TEST BANK ••••6789')->assertSee('On the way')->assertSee('Failed')->assertSee('$20.00')
        ->assertSee('does not match your Stripe details', false)->assertSeeHtml('data-payout-failure');

    // the site still holds the payout of the order that is not shipped
    $response->assertSeeHtml('data-earnings-holding')->assertSeeHtml('data-payout-row="'.$x['waiting']['sellerOrder']->id.'"')
        ->assertDontSeeHtml('data-payout-row="'.$x['paidOut']['sellerOrder']->id.'"')->assertDontSeeHtml('data-payout-row="'.$x['recent']['sellerOrder']->id.'"');
});

it('lists the transfers received, linked to the orders, and the money taken back', function () {
    $x = earningSeller($this);
    app(RefundService::class)->refundSellerOrderPartially(adminUser(), $x['paidOut']['sellerOrder']->fresh(), 2000, 'Scratched');
    syncAllLedgers();

    $page = voltPage($this, $x['seller'], $this->url, 'selling.earnings');
    $transfers = app(SellerEarnings::class)->transfers($x['seller'])->get();

    expect($transfers)->toHaveCount(3);      // two transfers and what was taken back from the first
    $page->assertCanSeeTableRecords($transfers)
        ->assertSee($x['paidOut']['order']->number)->assertSee($x['recent']['order']->number)->assertSee('Payout for your sale')->assertSee('Taken back for a refund')
        ->assertSee('$63.00')->assertSee('-$20.00')
        ->assertSee('pending until')->assertSee('available');
});

it('shows only the seller\'s own data, never another seller\'s', function () {
    $mine = earningSeller($this, inTransit: 1111, failed: 2222);
    $theirs = earningSeller($this, inTransit: 7777, failed: 8888);

    $html = $this->actingAs($mine['seller'])->get($this->url)->assertOk()->getContent();
    expect($html)->toContain('$11.11')->toContain('$22.22')->toContain($mine['paidOut']['order']->number)
        ->not->toContain('$77.77')->not->toContain('$88.88')
        ->not->toContain($theirs['paidOut']['order']->number)->not->toContain($theirs['waiting']['order']->number)
        ->not->toContain($theirs['account'])->not->toContain($theirs['seller']->sellerProfile->shop_name);

    $table = voltPage($this, $mine['seller'], $this->url, 'selling.earnings');
    $table->assertCanSeeTableRecords(app(SellerEarnings::class)->transfers($mine['seller'])->get())
        ->assertCanNotSeeTableRecords(app(SellerEarnings::class)->transfers($theirs['seller'])->get());

    // and the service never hands out lines of somebody else's account
    expect(app(SellerEarnings::class)->payouts($mine['seller'])->pluck('account')->unique()->all())->toBe([$mine['account']])
        ->and(app(SellerEarnings::class)->transfers($mine['seller'])->pluck('account')->unique()->all())->toBe([$mine['account']])
        ->and(app(SellerEarnings::class)->balances($mine['seller'])->pluck('account_key')->unique()->all())->toBe([$mine['account']]);
});

it('asks Stripe for the balance again when ours is older than a few minutes', function () {
    $x = earningSeller($this);
    config(['marketplace.ledger.balance_max_age_minutes' => 5]);
    $before = fakeGateway()->callCount('retrieveBalance');

    // fresh: no call
    $this->actingAs($x['seller'])->get($this->url)->assertOk();
    expect(fakeGateway()->callCount('retrieveBalance'))->toBe($before);

    // older than five minutes: the balance is read again (and a full sync is queued, which reads it once more)
    $this->travel(6)->minutes();
    fakeGateway()->recordPayout($x['account'], 500, ['status' => 'in_transit', 'id' => 'po_new_one']);
    $this->actingAs($x['seller'])->get($this->url)->assertOk()->assertSeeInOrder(['On its way to your bank', '$15.00']);
    expect(fakeGateway()->callCount('retrieveBalance'))->toBeGreaterThan($before)
        ->and(StripeBalance::forAccount($x['account'])->first()->refreshed_at->gt(now()->subMinute()))->toBeTrue();

    // right after: fresh again
    $calls = fakeGateway()->callCount('retrieveBalance');
    $this->actingAs($x['seller'])->get($this->url)->assertOk();
    expect(fakeGateway()->callCount('retrieveBalance'))->toBe($calls);
});

it('refreshes the balance on request, and shows a Stripe error as a message', function () {
    $x = earningSeller($this);
    $page = voltPage($this, $x['seller'], $this->url, 'selling.earnings');
    $calls = fakeGateway()->callCount('retrieveBalance');

    $page->call('refreshBalance')->assertNotified('Balance updated');
    expect(fakeGateway()->callCount('retrieveBalance'))->toBeGreaterThan($calls);

    fakeGateway()->failOn('retrieveBalance');
    $page->call('refreshBalance')->assertNotified('Could not reach Stripe');
});

it('does not break when Stripe cannot be reached while the page loads', function () {
    $x = earningSeller($this);
    $this->travel(10)->minutes();
    fakeGateway()->failOn('retrieveBalance');

    $this->actingAs($x['seller'])->get($this->url)->assertOk()->assertSee('Balance read from Stripe');
});

it('explains each way a seller can have no Stripe data', function () {
    // not connected
    $none = User::factory()->create();
    $this->actingAs($none)->get($this->url)->assertOk()->assertSeeHtml('data-earnings-state="none"')->assertSee('Connect Stripe')->assertDontSeeHtml('data-earnings-card');

    // an admin sells as the platform
    $this->actingAs(adminUser())->get($this->url)->assertOk()->assertSeeHtml('data-earnings-state="platform"')->assertSee('You sell as the platform');

    // a demo seller has a made-up Stripe account
    $demo = App\Models\SellerProfile::where('stripe_account_id', 'like', 'acct_demo%')->firstOrFail()->user;
    $this->actingAs($demo)->get($this->url)->assertOk()->assertSeeHtml('data-earnings-state="demo"')->assertSee('demo account');

    // connected but nothing has happened yet
    $fresh = onboardedSeller();
    $this->actingAs($fresh)->get($this->url)->assertOk()->assertSeeHtml('data-earnings-card="available"')
        ->assertSee('No payouts yet')->assertSee('Nothing is waiting to be paid out')->assertSee('No transfers yet');
});

it('is in the sidebar and linked from the selling dashboard', function () {
    $x = earningSeller($this);

    $this->actingAs($x['seller'])->get(route('selling.dashboard'))->assertOk()
        ->assertSee(route('selling.earnings'), false)->assertSee('Earnings')->assertSee('Your earnings and payouts');
    expect(route('selling.earnings'))->toEndWith('/dashboard/selling/earnings');
});

// --- the selling dashboard totals ---------------------------------------------------------------------------------------------------------

it('takes "paid out" on the selling dashboard from the ledger, net of what was taken back', function () {
    $seller = onboardedSeller();
    $order = chargedListingOrder($this->buyer, $seller);
    app(ShipmentService::class)->markShipped($seller, $order['sellerOrder'], 'UPS', '1Z1', null);
    $stat = fn () => voltPage($this, $seller, '/dashboard/selling', 'selling.dashboard');

    // Stripe has not been read: the site's own records say 6300 were transferred
    expect(app(SellerEarnings::class)->paidOut($seller))->toBe(['cents' => 6300, 'confirmed' => false]);
    $stat()->assertSeeHtml('data-stat="payouts"')->assertSee('$63.00')->assertDontSee('as Stripe shows it');

    syncAllLedgers();
    expect(app(SellerEarnings::class)->paidOut($seller))->toBe(['cents' => 6300, 'confirmed' => true]);
    $stat()->assertSee('$63.00')->assertSee('as Stripe shows it');

    // Stripe's ledger is the truth: part of the transfer was taken back
    app(RefundService::class)->refundSellerOrderPartially(adminUser(), $order['sellerOrder']->fresh(), 2000, 'Scratched');
    syncAllLedgers();
    expect(app(SellerEarnings::class)->paidOut($seller)['cents'])->toBe(4300);
    $stat()->assertSeeInOrder(['Paid out', '$43.00']);
});

it('keeps "paid out" at the site\'s records for sellers without a ledger (demo sellers)', function () {
    $demo = App\Models\SellerProfile::where('stripe_account_id', 'like', 'acct_demo%')->firstOrFail()->user;
    $records = (int) App\Models\SellerOrder::where('seller_id', $demo->id)->whereNotNull('transferred_at')->whereNotIn('status', ['refunded', 'cancelled', 'pending'])->sum('payout_cents');

    expect(app(SellerEarnings::class)->paidOut($demo))->toBe(['cents' => $records, 'confirmed' => false]);
});

it('counts what the site still holds for a seller from all their unpaid payouts', function () {
    $x = earningSeller($this);

    expect(app(SellerEarnings::class)->holdingCents($x['seller']))->toBe(6300)
        ->and(app(SellerEarnings::class)->holdingCents(adminUser()))->toBe(0)
        ->and(app(SellerEarnings::class)->upcoming($x['seller']))->toHaveCount(1)
        ->and(app(SellerEarnings::class)->upcoming($x['seller'])->first()['outlook']['state'])->toBe('after_ship');
});
