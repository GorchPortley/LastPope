<?php

use App\Exceptions\Marketplace\ConnectException;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Marketplace\ConnectOnboardingService;

beforeEach(function () {
    config(['marketplace.default_country' => 'US']);
    $this->user = User::factory()->create(['name' => 'Ada Builder']);
});

it('creates the profile and the Express account on first use and sends the seller to Stripe', function () {
    $this->actingAs($this->user)->get(route('selling.connect'))
        ->assertRedirect('https://connect.stripe.test/setup/acct_fake_1');

    $profile = $this->user->sellerProfile()->first();
    expect($profile)->not->toBeNull()
        ->and($profile->shop_name)->toBe('Ada Builder')
        ->and($profile->country)->toBe('US')
        ->and($profile->stripe_account_id)->toBe('acct_fake_1')
        ->and($profile->canReceivePayouts())->toBeFalse();

    $link = fakeGateway()->lastCall('createOnboardingLink');
    expect($link['returnUrl'])->toBe(route('selling.connect.return'))
        ->and($link['refreshUrl'])->toBe(route('selling.connect.refresh'));
});

it('continues with the same account on the second visit and when the link expired', function () {
    $this->actingAs($this->user)->get(route('selling.connect'));
    $this->actingAs($this->user)->get(route('selling.connect'))->assertRedirect('https://connect.stripe.test/setup/acct_fake_1');
    $this->actingAs($this->user)->get(route('selling.connect.refresh'))->assertRedirect('https://connect.stripe.test/setup/acct_fake_1');

    expect(fakeGateway()->callCount('createConnectedAccount'))->toBe(1)
        ->and(fakeGateway()->callCount('createOnboardingLink'))->toBe(3)
        ->and(SellerProfile::where('user_id', $this->user->id)->count())->toBe(1);
});

it('keeps guests out', function () {
    $this->get(route('selling.connect'))->assertRedirect();
    $this->get(route('selling.connect.return'))->assertRedirect();
    $this->get(route('selling.dashboard-link'))->assertRedirect();

    expect(fakeGateway()->callCount('createConnectedAccount'))->toBe(0);
});

it('uses the country and shop name chosen before the account exists', function () {
    $profile = app(ConnectOnboardingService::class)->ensureAccount($this->user, 'Klang Werkstatt', 'de');

    expect($profile->country)->toBe('DE')
        ->and($profile->shop_name)->toBe('Klang Werkstatt')
        ->and(fakeGateway()->lastCall('createConnectedAccount')['country'])->toBe('DE');
});

it('reads the account status when the seller returns from Stripe', function () {
    $this->actingAs($this->user)->get(route('selling.connect'));
    $accountId = $this->user->sellerProfile()->first()->stripe_account_id;

    fakeGateway()->setAccountStatus($accountId, true, true, true);
    $this->actingAs($this->user)->get(route('selling.connect.return'))->assertRedirect()->assertSessionHas('success');

    expect($this->user->fresh()->canReceivePayouts())->toBeTrue()
        ->and($this->user->sellerProfile()->first()->details_submitted)->toBeTrue();
});

it('tells a seller whose details Stripe is still checking to wait', function () {
    $this->actingAs($this->user)->get(route('selling.connect'));
    $accountId = $this->user->sellerProfile()->first()->stripe_account_id;

    fakeGateway()->setAccountStatus($accountId, false, false, true);
    $this->actingAs($this->user)->get(route('selling.connect.return'))->assertRedirect()->assertSessionHas('info');

    fakeGateway()->setAccountStatus($accountId, false, false, false);
    $this->actingAs($this->user)->get(route('selling.connect.return'))->assertRedirect()->assertSessionHas('info');

    expect($this->user->fresh()->canReceivePayouts())->toBeFalse();
});

it('sends somebody who never started onboarding back to the start when they hit the return URL', function () {
    $this->actingAs($this->user)->get(route('selling.connect.return'))->assertRedirect(route('selling.connect'));
});

it('hands out the Express dashboard link only after onboarding was submitted', function () {
    $this->actingAs($this->user)->get(route('selling.dashboard-link'))->assertRedirect()->assertSessionHas('error');

    $this->actingAs($this->user)->get(route('selling.connect'));
    $accountId = $this->user->sellerProfile()->first()->stripe_account_id;
    fakeGateway()->setAccountStatus($accountId, false, false, false);
    $this->actingAs($this->user)->get(route('selling.dashboard-link'))->assertRedirect()->assertSessionHas('error');

    // the local flag was stale: Stripe says it was submitted
    fakeGateway()->setAccountStatus($accountId, true, true, true);
    $this->actingAs($this->user)->get(route('selling.dashboard-link'))->assertRedirect('https://connect.stripe.test/express/'.$accountId);
});

it('shows a friendly error when Stripe is unreachable', function () {
    fakeGateway()->failOn('createConnectedAccount');

    $this->actingAs($this->user)->get(route('selling.connect'))->assertRedirect()->assertSessionHas('error');

    expect($this->user->sellerProfile()->first()->stripe_account_id)->toBeNull();

    // and the next try works, reusing the profile
    $this->actingAs($this->user)->get(route('selling.connect'))->assertRedirect('https://connect.stripe.test/setup/acct_fake_1');
});

it('lets the service refresh and apply status directly', function () {
    $connect = app(ConnectOnboardingService::class);
    $profile = $connect->ensureAccount($this->user);

    expect(fn () => $connect->refreshStatus(User::factory()->create()))->toThrow(ConnectException::class);

    fakeGateway()->setAccountStatus($profile->stripe_account_id, true, false, true);
    $refreshed = $connect->refreshStatus($this->user);

    expect($refreshed->charges_enabled)->toBeTrue()->and($refreshed->payouts_enabled)->toBeFalse();
});

it('makes paid items sellable once onboarding is finished', function () {
    $connect = app(ConnectOnboardingService::class);
    $profile = $connect->ensureAccount($this->user);
    $design = App\Models\Design::factory()->paid(1500)->withSnapshot()->for($this->user, 'designer')->create();

    expect($design->isAvailableForPurchase())->toBeFalse();

    fakeGateway()->setAccountStatus($profile->stripe_account_id, true, true, true);
    $connect->refreshStatus($this->user);

    expect($design->fresh()->isAvailableForPurchase())->toBeTrue();
});
