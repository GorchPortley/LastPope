<?php

use App\Models\Design;
use App\Models\Driver;
use App\Models\Listing;
use App\Models\User;

it('forgets the stored discussions and forum accounts', function () {
    $design = Design::factory()->create(['forum_slug' => '5-my-design']);
    $driver = Driver::factory()->create(['forum_slug' => '6-my-driver']);
    $listing = Listing::factory()->create(['forum_slug' => '7-my-listing']);
    $member = User::factory()->create(['forum_user_id' => 42]);
    $untouched = Design::factory()->create(['forum_slug' => null]);

    $this->artisan('forum:unlink --force')
        ->expectsOutputToContain('forum:setup --backfill')
        ->assertSuccessful();

    expect($design->fresh()->forum_slug)->toBeNull()
        ->and($driver->fresh()->forum_slug)->toBeNull()
        ->and($listing->fresh()->forum_slug)->toBeNull()
        ->and($member->fresh()->forum_user_id)->toBeNull()
        ->and($untouched->fresh()->forum_slug)->toBeNull();
});

it('keeps the members stored forum password, so their forum account can be recreated', function () {
    $member = User::factory()->create(['forum_user_id' => 42, 'forum_password' => 'secret-forum-pass']);

    $this->artisan('forum:unlink --force')->assertSuccessful();

    expect($member->fresh()->forum_password)->toBe('secret-forum-pass');
});

it('does nothing without confirmation', function () {
    $design = Design::factory()->create(['forum_slug' => '5-my-design']);

    $this->artisan('forum:unlink')->expectsConfirmation('Forget every stored forum discussion and forum account? Run this only when the forum itself was wiped.', 'no')->assertFailed();

    expect($design->fresh()->forum_slug)->toBe('5-my-design');
});
