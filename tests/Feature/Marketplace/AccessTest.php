<?php

use App\Enums\EntitlementSource;
use App\Models\Design;
use App\Models\Driver;
use App\Models\Entitlement;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/*
| User::hasAccessTo(): owner, admin, an unrevoked entitlement, or a free digital item
| (which hands out a `free` entitlement). Logged-in users only.
*/

it('lets the owner in without any entitlement', function () {
    $owner = onboardedSeller();
    $design = paidDesign($owner, withFile: false);
    $before = Entitlement::count();

    expect($owner->hasAccessTo($design))->toBeTrue()
        ->and(Entitlement::count())->toBe($before) // nothing is created for owners
        ->and($owner->entitlementFor($design))->toBeNull();
});

it('lets admins in', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->hasAccessTo(paidDesign(withFile: false)))->toBeTrue();
});

it('keeps strangers out of a paid design', function () {
    $stranger = User::factory()->create();

    expect($stranger->hasAccessTo(paidDesign(withFile: false)))->toBeFalse();
});

it('keeps guests out', function () {
    $guest = new User();
    $paid = paidDesign(withFile: false);
    $free = Design::factory()->withSnapshot()->create();

    expect($guest->hasAccessTo($paid))->toBeFalse()
        ->and($guest->hasAccessTo($free))->toBeFalse()
        ->and(Gate::forUser(null)->allows('download', $free))->toBeFalse();
});

it('lets a buyer in with an entitlement and out once it is revoked', function () {
    $buyer = User::factory()->create();
    $design = paidDesign(withFile: false);

    $entitlement = $buyer->grantEntitlement($design);
    expect($buyer->hasAccessTo($design))->toBeTrue();

    $entitlement->revoke();
    expect($buyer->hasAccessTo($design))->toBeFalse();

    // buying it again revives the same row
    $again = $buyer->grantEntitlement($design);
    expect($again->is($entitlement))->toBeTrue()
        ->and($buyer->hasAccessTo($design))->toBeTrue()
        ->and($buyer->entitlements()->count())->toBe(1);
});

it('grants a free entitlement the first time a free design is accessed', function () {
    $user = User::factory()->create();
    $design = Design::factory()->withSnapshot()->create();

    expect($user->hasAccessTo($design))->toBeTrue();

    $entitlement = $user->entitlementFor($design);
    expect($entitlement)->not->toBeNull()
        ->and($entitlement->source)->toBe(EntitlementSource::FREE);

    $user->hasAccessTo($design); // idempotent
    expect($user->entitlements()->count())->toBe(1);
});

it('does not grant anything for a side-effect free check', function () {
    $user = User::factory()->create();
    $design = Design::factory()->withSnapshot()->create();

    expect($user->hasAccessTo($design, grantFree: false))->toBeTrue()
        ->and($user->entitlements()->count())->toBe(0);
});

it('does not give free access to drafts or to designs with nothing to download', function () {
    $user = User::factory()->create();

    expect($user->hasAccessTo(Design::factory()->draft()->withSnapshot()->create()))->toBeFalse()
        ->and($user->hasAccessTo(Design::factory()->create()))->toBeFalse()   // no snapshot
        ->and($user->entitlements()->count())->toBe(0);
});

it('does not hand a free entitlement back after an admin revoked it', function () {
    $user = User::factory()->create();
    $design = Design::factory()->withSnapshot()->create();

    $user->hasAccessTo($design);
    $user->revokeEntitlement($design);

    expect($user->hasAccessTo($design))->toBeFalse()
        ->and($user->entitlements()->count())->toBe(1);
});

it('keeps a purchase entitlement when the same item is later accessed for free', function () {
    $user = User::factory()->create();
    $design = paidDesign(withFile: false);

    $user->grantEntitlement($design, EntitlementSource::PURCHASE);
    $design->update(['price_cents' => 0]);
    $user->grantEntitlement($design->refresh(), EntitlementSource::FREE);

    expect($user->entitlementFor($design)->source)->toBe(EntitlementSource::PURCHASE);
});

it('never gives access to physical goods, except to the seller and admins', function () {
    $listing = Listing::factory()->create();
    $buyer = User::factory()->create();

    expect($listing->seller->hasAccessTo($listing))->toBeTrue()
        ->and(User::factory()->admin()->create()->hasAccessTo($listing))->toBeTrue()
        ->and($buyer->hasAccessTo($listing))->toBeFalse()
        ->and(fn () => $buyer->grantEntitlement($listing))->toThrow(InvalidArgumentException::class);
});

it('exposes the rule through the download policy', function () {
    $buyer = User::factory()->create();
    $stranger = User::factory()->create();
    $design = paidDesign(withFile: false);
    $buyer->grantEntitlement($design);

    expect(Gate::forUser($buyer)->allows('download', $design))->toBeTrue()
        ->and(Gate::forUser($stranger)->denies('download', $design))->toBeTrue()
        ->and(Gate::forUser($design->designer)->allows('update', $design))->toBeTrue()
        ->and(Gate::forUser($stranger)->denies('update', $design))->toBeTrue()
        ->and(Gate::forUser($stranger)->denies('delete', $design))->toBeTrue()
        ->and(Gate::forUser(User::factory()->admin()->create())->allows('delete', $design))->toBeTrue();
});

it('has update and delete policies for listings and drivers too', function () {
    $listing = Listing::factory()->create();
    $driver = Driver::factory()->create();
    $stranger = User::factory()->create();

    expect(Gate::forUser($listing->seller)->allows('update', $listing))->toBeTrue()
        ->and(Gate::forUser($stranger)->denies('update', $listing))->toBeTrue()
        ->and(Gate::forUser($stranger)->denies('download', $listing))->toBeTrue()
        ->and(Gate::forUser($driver->creator)->allows('delete', $driver))->toBeTrue()
        ->and(Gate::forUser($stranger)->denies('delete', $driver))->toBeTrue();
});

it('makes the measurement downloads of an active driver public and keeps inactive ones for the owner and admins', function () {
    $active = Driver::factory()->create();
    $inactive = Driver::factory()->draft()->create();
    $stranger = User::factory()->create();

    expect(Gate::forUser($stranger)->allows('download', $active))->toBeTrue()
        ->and(Gate::forUser($active->creator)->allows('download', $active))->toBeTrue()
        ->and(Gate::forUser($stranger)->denies('download', $inactive))->toBeTrue()
        ->and(Gate::forUser($inactive->creator)->allows('download', $inactive))->toBeTrue()
        ->and(Gate::forUser(User::factory()->admin()->create())->allows('download', $inactive))->toBeTrue()
        // guests may download too (the policy takes a nullable user)
        ->and(Gate::forUser(null)->allows('download', $active))->toBeTrue()
        ->and(Gate::allows('download', $inactive))->toBeFalse();
});
