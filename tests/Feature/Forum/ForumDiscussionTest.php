<?php

use App\Enums\ListingStatus;
use App\Jobs\CreateForumDiscussion;
use App\Models\Design;
use App\Models\Driver;
use App\Models\Listing;
use App\Models\User;
use App\Services\Forum\ForumClient;
use App\Services\Forum\NullForumClient;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeForumClient;

beforeEach(function () {
    config([
        'forum.tags.design.slug' => 'designs',
        'forum.tags.driver.slug' => 'drivers',
        'forum.tags.listing.slug' => 'marketplace',
    ]);
});

it('uses the no-op client when the forum is disabled (the default, and always in tests)', function () {
    expect(config('forum.enabled'))->toBeFalse()
        ->and(app(ForumClient::class))->toBeInstanceOf(NullForumClient::class);
});

it('does not queue a discussion while the forum is disabled', function () {
    Queue::fake();

    Design::factory()->create();
    Driver::factory()->create();

    Queue::assertNotPushed(CreateForumDiscussion::class);
});

it('queues a discussion for a design as soon as it is created, hidden or not', function () {
    FakeForumClient::install();
    Queue::fake();

    $draft = Design::factory()->draft()->create();
    Queue::assertPushed(CreateForumDiscussion::class, fn ($job) => $job->subject->is($draft));
});

it('queues a discussion for a driver only once it is public', function () {
    FakeForumClient::install();
    Queue::fake();

    $draft = Driver::factory()->draft()->create();
    Queue::assertNotPushed(CreateForumDiscussion::class);

    $draft->update(['active' => true]);
    Queue::assertPushed(CreateForumDiscussion::class, fn ($job) => $job->subject->is($draft));
});

it('queues a discussion for a new active driver, but not twice for one that has a thread', function () {
    FakeForumClient::install();
    Queue::fake();

    $driver = Driver::factory()->create(['active' => true]);
    Queue::assertPushed(CreateForumDiscussion::class, 1);

    $withThread = Driver::factory()->create(['active' => true, 'forum_slug' => '5-existing']);
    Queue::assertPushed(CreateForumDiscussion::class, 1);
    expect($withThread->forum_slug)->toBe('5-existing');
});

it('skips kinds whose tag slug is empty', function () {
    FakeForumClient::install();
    Queue::fake();
    config(['forum.tags.driver.slug' => '']);

    Driver::factory()->create(['active' => true]);

    Queue::assertNotPushed(CreateForumDiscussion::class);
});

it('creates the discussion as the owner (no logged-in user needed) and stores the slug', function () {
    $forum = FakeForumClient::install();
    $forum->tags = ['designs' => 5];
    Queue::fake();

    $owner = User::factory()->create();
    $design = Design::factory()->for($owner, 'designer')->create(['name' => 'Tower Mk2', 'summary' => '<p>A <b>small</b> tower</p>']);

    expect(auth()->check())->toBeFalse();

    (new CreateForumDiscussion($design))->handle($forum);

    expect($forum->discussions)->toHaveCount(1);
    $discussion = $forum->discussions[0];

    expect($discussion['author'])->toBe($owner->id)
        ->and($discussion['tag'])->toBe('designs')
        ->and($discussion['title'])->toBe('Tower Mk2')
        ->and($discussion['content'])->toContain('A small tower')->toContain(url('/designs/design/'.$design->id));

    expect($design->fresh()->forum_slug)->toBe('1-tower-mk2');
});

it('does not create a second discussion when the job runs twice', function () {
    $forum = FakeForumClient::install();
    $forum->tags = ['designs' => 5];
    Queue::fake();

    $design = Design::factory()->create();

    (new CreateForumDiscussion($design))->handle($forum);
    (new CreateForumDiscussion($design))->handle($forum);

    expect($forum->discussions)->toHaveCount(1);
});

it('does nothing in the job when the forum got disabled meanwhile or the driver is a draft', function () {
    Queue::fake();
    $design = Design::factory()->create();
    $draft = Driver::factory()->draft()->create();

    $forum = FakeForumClient::install();
    $forum->tags = ['designs' => 5, 'drivers' => 3];
    (new CreateForumDiscussion($draft))->handle($forum);

    $disabled = new FakeForumClient(false);
    (new CreateForumDiscussion($design))->handle($disabled);

    expect($forum->discussions)->toBeEmpty()->and($disabled->discussions)->toBeEmpty()
        ->and($design->fresh()->forum_slug)->toBeNull();
});

it('titles driver discussions with brand and model and uses the driver tag', function () {
    $forum = FakeForumClient::install();
    $forum->tags = ['drivers' => 3];
    Queue::fake();

    $creator = User::factory()->create();
    $driver = Driver::factory()->create(['user_id' => $creator->id, 'brand' => 'SB Acoustics', 'model' => 'SB26STAC', 'active' => true]);

    (new CreateForumDiscussion($driver))->handle($forum);

    expect($forum->discussions[0])->toMatchArray(['title' => 'SB Acoustics SB26STAC', 'tag' => 'drivers', 'author' => $creator->id])
        ->and($forum->discussions[0]['content'])->toContain(url('/drivers/driver/'.$driver->id));
});

it('opens a discussion for an active listing when the table can store the slug', function () {
    $forum = FakeForumClient::install();
    Queue::fake();

    $listing = Listing::factory()->create(['status' => ListingStatus::ACTIVE]);

    if (! \Illuminate\Support\Facades\Schema::hasColumn('listings', 'forum_slug')) {
        Queue::assertNotPushed(CreateForumDiscussion::class);
        $this->markTestSkipped('listings.forum_slug does not exist yet');
    }

    Queue::assertPushed(CreateForumDiscussion::class, fn ($job) => $job->subject->is($listing));
});
