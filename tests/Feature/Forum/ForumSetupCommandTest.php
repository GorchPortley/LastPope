<?php

use Tests\Support\FakeForumClient;

it('refuses to run while the forum is disabled', function () {
    $this->artisan('forum:setup')
        ->expectsOutputToContain('The forum is disabled')
        ->assertFailed();
});

it('creates the missing tags and reports a table', function () {
    $forum = FakeForumClient::install();
    $forum->tags = ['designs' => 1];

    $this->artisan('forum:setup')
        ->expectsOutputToContain('Forum is ready')
        ->assertSuccessful();

    expect($forum->tags)->toHaveKeys(['designs', 'drivers', 'marketplace']);
});

it('only reports with --check and fails when a tag is missing', function () {
    $forum = FakeForumClient::install();
    $forum->tags = ['designs' => 1];

    $this->artisan('forum:setup --check')->assertFailed();

    expect($forum->tags)->toBe(['designs' => 1]);
});

it('skips a kind whose tag slug is empty', function () {
    $forum = FakeForumClient::install();
    config(['forum.tags.listing.slug' => '']);

    $this->artisan('forum:setup')->assertSuccessful();

    expect($forum->tags)->toHaveKeys(['designs', 'drivers'])->not->toHaveKey('marketplace');
});

it('fails with the reason when the forum is unreachable or the key is wrong', function () {
    $forum = FakeForumClient::install();
    $forum->reachable = false;

    $this->artisan('forum:setup')->expectsOutputToContain('connection refused')->assertFailed();

    $forum->reachable = true;
    $forum->authorized = false;

    $this->artisan('forum:setup')->assertFailed();
    expect($forum->tags)->toBe([]);
});

it('flags an existing tag that is only a secondary tag', function () {
    $forum = FakeForumClient::install();
    $forum->tags = ['designs' => 1, 'drivers' => 2, 'marketplace' => 3];
    $forum->secondaryTags = ['drivers'];

    $this->artisan('forum:setup')->expectsOutputToContain('secondary tag')->assertFailed();
});

it('queues discussions for public items without one when asked to backfill', function () {
    \Illuminate\Support\Facades\Queue::fake();

    // Created while the forum is still disabled, i.e. before it was connected.
    $owner = \App\Models\User::factory()->create();
    $public = \App\Models\Design::factory()->for($owner, 'designer')->count(2)->create();
    $draft = \App\Models\Design::factory()->for($owner, 'designer')->draft()->create();
    $threaded = \App\Models\Design::factory()->for($owner, 'designer')->create(['forum_slug' => '1-has-thread']);

    FakeForumClient::install();

    $this->artisan('forum:setup')->assertSuccessful();
    \Illuminate\Support\Facades\Queue::assertNothingPushed();

    $this->artisan('forum:setup --backfill')->expectsOutputToContain('discussion(s)')->assertSuccessful();

    foreach ($public as $design) {
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\CreateForumDiscussion::class, fn ($job) => $job->subject->is($design));
    }

    \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\CreateForumDiscussion::class, fn ($job) => $job->subject->is($draft) || $job->subject->is($threaded));
});
