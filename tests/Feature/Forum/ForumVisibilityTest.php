<?php

use App\Http\Livewire\DesignDescription;
use App\Jobs\CreateForumDiscussion;
use App\Jobs\SyncForumVisibility;
use App\Models\Design;
use App\Models\Driver;
use App\Models\User;
use App\Services\Forum\ForumException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeForumClient;

/*
| R5.2: who can see the forum discussion of a design follows the design. Hidden: designer + admins. Active and free:
| everybody. Active and paid: designer + admins + buyers (unrevoked entitlements). The job computes that from the
| database and tells the forum; the observers queue it; the Discussion tab shows the thread only to those who can see it.
*/

/** A design with a forum discussion, as it is after CreateForumDiscussion ran (made without the observers: no job runs yet). */
function threadedDesign(array $attributes = []): Design
{
    $designer = User::factory()->create();

    $attributes += ['forum_slug' => '7-a-design', 'storage_key' => (string) Illuminate\Support\Str::uuid()];

    return Design::withoutEvents(fn () => Design::factory()->for($designer, 'designer')->create($attributes));
}

/** Run the job against the fake forum and return what the forum was told for the design's discussion. */
function syncedAs(Design $design, FakeForumClient $forum): ?array
{
    (new SyncForumVisibility($design))->handle($forum);

    return $forum->visibility[$design->forum_slug];
}

/** The queue worker releases the "unique until processing" lock when it starts the job; Queue::fake() never does. */
function jobStarts(Design $design): void
{
    (new Illuminate\Bus\UniqueLock(app(Illuminate\Contracts\Cache\Repository::class)))->release(new SyncForumVisibility($design));
}

// --- the state table ------------------------------------------------------------------------------

it('applies the state table: hidden = designer + admins, free = public, paid = designer + admins + buyers', function (array $attributes, bool $public, bool $buyerIncluded) {
    $forum = FakeForumClient::install();
    $admin = User::factory()->admin()->create();
    $buyer = User::factory()->create();
    $revoked = User::factory()->create();
    $design = threadedDesign($attributes);

    $buyer->grantEntitlement($design);
    $revoked->grantEntitlement($design);
    $revoked->revokeEntitlement($design);

    $members = syncedAs($design, $forum);

    if ($public) {
        expect($members)->toBeNull();

        return;
    }

    expect($members)->toContain($design->user_id, $admin->id)
        ->and(in_array($buyer->id, $members, true))->toBe($buyerIncluded)
        ->and($members)->not->toContain($revoked->id);
})->with([
    'hidden, free' => [['active' => false, 'price_cents' => 0], false, false],
    'hidden, paid (buyers lose it while the design is hidden)' => [['active' => false, 'price_cents' => 1500], false, false],
    'active, free' => [['active' => true, 'price_cents' => 0], true, false],
    'active, paid' => [['active' => true, 'price_cents' => 1500], false, true],
]);

it('recomputes from the database on every run, whatever the forum looked like before', function () {
    $forum = FakeForumClient::install();
    $design = threadedDesign(['price_cents' => 1500]);
    $buyer = User::factory()->create();
    $buyer->grantEntitlement($design);

    $forum->visibility[$design->forum_slug] = [999]; // the forum drifted: somebody else is on the list

    expect(syncedAs($design, $forum))->toContain($design->user_id, $buyer->id)->not->toContain(999);

    // refund
    $buyer->revokeEntitlement($design);
    expect(syncedAs($design, $forum))->toContain($design->user_id)->not->toContain($buyer->id);

    // price to 0: public again, and a second run changes nothing
    $design->update(['price_cents' => 0]);
    expect(syncedAs($design, $forum))->toBeNull();
    expect(syncedAs($design, $forum))->toBeNull();
});

it('does nothing without a discussion, for a deleted design, or while the forum is disabled', function () {
    $forum = FakeForumClient::install();
    $design = threadedDesign(['forum_slug' => null]);

    (new SyncForumVisibility($design))->handle($forum);
    expect($forum->visibilityCalls)->toBeEmpty();

    $threaded = threadedDesign();
    $job = new SyncForumVisibility($threaded);
    $threaded->delete();
    $job->handle($forum);
    expect($forum->visibilityCalls)->toBeEmpty();

    (new SyncForumVisibility(threadedDesign()))->handle(new FakeForumClient(false));
    expect($forum->visibilityCalls)->toBeEmpty();
});

it('is one job per design that is not queued twice while waiting, and never runs twice at once', function () {
    $job = new SyncForumVisibility(threadedDesign());

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueId())->toBe((string) $job->design->id)
        ->and($job->middleware()[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($job->backoff())->toBe([60, 300, 900]);
});

// --- triggers -----------------------------------------------------------------------------------------

it('queues a sync when a design is shown, hidden or re-priced, and for nothing else', function () {
    FakeForumClient::install();
    $design = threadedDesign();
    Queue::fake();

    $design->update(['name' => 'Renamed']);
    Queue::assertNothingPushed();

    $design->update(['price_cents' => 2500]);
    Queue::assertPushed(SyncForumVisibility::class, 1);

    // a job that is still waiting covers the next change (it reads the database when it runs)
    $design->update(['active' => false]);
    Queue::assertPushed(SyncForumVisibility::class, 1);

    jobStarts($design);
    $design->update(['active' => true]);
    Queue::assertPushed(SyncForumVisibility::class, 2);

    // no discussion yet: CreateForumDiscussion does it after opening one
    threadedDesign(['forum_slug' => null])->update(['price_cents' => 900]);
    Queue::assertPushed(SyncForumVisibility::class, 2);
    Queue::assertPushed(CreateForumDiscussion::class, 1);
});

it('queues a sync when a buyer is added or refunded, but only where buyers matter', function () {
    FakeForumClient::install();
    $paid = threadedDesign(['price_cents' => 1500]);
    $free = threadedDesign(['price_cents' => 0]);
    $hidden = threadedDesign(['price_cents' => 1500, 'active' => false]);
    $buyer = User::factory()->create();
    Queue::fake();

    foreach ([$free, $hidden] as $design) {
        $buyer->grantEntitlement($design);
    }
    Queue::assertNothingPushed();

    $buyer->grantEntitlement($paid);
    Queue::assertPushed(SyncForumVisibility::class, fn ($job) => $job->design->is($paid));

    jobStarts($paid);
    $buyer->revokeEntitlement($paid);
    Queue::assertPushed(SyncForumVisibility::class, 2);

    // an admin restoring the access in the admin screens
    jobStarts($paid);
    $buyer->entitlementFor($paid)->forceFill(['revoked_at' => null])->save();
    Queue::assertPushed(SyncForumVisibility::class, 3);
});

it('makes the thread of a hidden design private in the same run that opens it, and leaves a driver public', function () {
    $forum = FakeForumClient::install();
    $forum->tags = ['designs' => 5, 'drivers' => 3];
    Queue::fake();

    $design = Design::factory()->draft()->create();
    $driver = Driver::factory()->create(['active' => true]);

    (new CreateForumDiscussion($design))->handle($forum);
    (new CreateForumDiscussion($driver))->handle($forum);

    expect($forum->discussions)->toHaveCount(2)
        ->and($forum->visibility[$design->fresh()->forum_slug])->toContain($design->designer->getKey())
        ->and($forum->visibility)->not->toHaveKey($driver->fresh()->forum_slug);
    Queue::assertNotPushed(SyncForumVisibility::class);
});

it('queues the sync as the retry when restricting a new thread fails', function () {
    $forum = new class extends FakeForumClient {
        public function makeDiscussionPrivate(string $discussion, iterable $members): void
        {
            throw new ForumException('Flarum could not change the discussion (HTTP 500)', 500);
        }
    };
    app()->instance(App\Services\Forum\ForumClient::class, $forum);
    $forum->tags = ['designs' => 5];
    Queue::fake();

    $design = Design::factory()->draft()->create();
    (new CreateForumDiscussion($design))->handle($forum);

    expect($design->fresh()->forum_slug)->not->toBeNull();
    Queue::assertPushed(SyncForumVisibility::class, fn ($job) => $job->design->is($design));
});

it('never lets a forum or queue problem break saving a design or booking a purchase', function () {
    FakeForumClient::install();
    $design = threadedDesign(['price_cents' => 1500]);
    $buyer = User::factory()->create();

    // the queue is "sync" in tests, so the job runs right there; the forum is unreachable
    $unreachable = new class extends FakeForumClient {
        public int $attempts = 0;

        public function makeDiscussionPrivate(string $discussion, iterable $members): void
        {
            $this->attempts++;

            throw new ForumException('Flarum is down.');
        }
    };
    app()->instance(App\Services\Forum\ForumClient::class, $unreachable);

    $design->update(['price_cents' => 2000]);
    $entitlement = $buyer->grantEntitlement($design);

    expect($unreachable->attempts)->toBe(2)
        ->and($design->fresh()->price_cents)->toBe(2000)
        ->and($entitlement->exists)->toBeTrue();
});

// --- the Discussion tab ---------------------------------------------------------------------------------

it('shows the thread to the designer, buyers and admins and a note to everybody else on a paid design', function () {
    FakeForumClient::install();

    $design = threadedDesign(['price_cents' => 1500]);
    $buyer = User::factory()->create();
    $buyer->grantEntitlement($design);
    $refunded = User::factory()->create();
    $refunded->grantEntitlement($design);
    $refunded->revokeEntitlement($design);

    $tab = fn (?User $viewer) => $viewer
        ? Livewire::actingAs($viewer)->test(DesignDescription::class, ['design' => $design])
        : Livewire::test(DesignDescription::class, ['design' => $design]);

    // the guest first: actingAs() keeps its user for the rest of the test
    $tab(null)->assertDontSeeHtml('<iframe')->assertSee('The discussion for this design is open to buyers.')->assertSee('Log in to buy this design');

    foreach ([User::factory()->create(), $refunded] as $outsider) {
        $tab($outsider)->assertDontSeeHtml('<iframe')->assertSee('The discussion for this design is open to buyers.')->assertSee('Buy this design to join it.');
    }

    foreach ([$design->designer, $buyer, User::factory()->admin()->create()] as $member) {
        $tab($member)->assertSeeHtml('/forum/embed/7-a-design')->assertDontSee('open to buyers');
    }
});

it('shows the thread to everybody on a free design, and the old text without a discussion', function () {
    FakeForumClient::install();

    Livewire::test(DesignDescription::class, ['design' => threadedDesign(['price_cents' => 0])])->assertSeeHtml('/forum/embed/7-a-design');

    Livewire::test(DesignDescription::class, ['design' => threadedDesign(['price_cents' => 1500, 'forum_slug' => null])])
        ->assertDontSeeHtml('<iframe')->assertSee('No discussion data available');
});

// --- the re-sync command ------------------------------------------------------------------------------------

it('re-syncs every design that has a discussion with forum:sync-visibility', function () {
    $paid = threadedDesign(['price_cents' => 1500, 'forum_slug' => '1-paid']);
    $free = threadedDesign(['price_cents' => 0, 'forum_slug' => '2-free']);
    $broken = threadedDesign(['price_cents' => 1500, 'forum_slug' => '3-broken']);
    threadedDesign(['forum_slug' => null]);

    $forum = new class extends FakeForumClient {
        public function makeDiscussionPrivate(string $discussion, iterable $members): void
        {
            if ($discussion === '3-broken') {
                throw new ForumException('Flarum could not make the discussion private (HTTP 404).');
            }

            parent::makeDiscussionPrivate($discussion, $members);
        }
    };
    app()->instance(App\Services\Forum\ForumClient::class, $forum);

    $this->artisan('forum:sync-visibility')
        ->expectsOutputToContain('design #'.$broken->id)
        ->assertFailed();

    expect($forum->visibility)->toHaveKeys(['1-paid', '2-free'])->not->toHaveKey('3-broken')
        ->and($forum->visibility['1-paid'])->toContain($paid->user_id)
        ->and($forum->visibility['2-free'])->toBeNull()
        ->and($forum->visibilityCalls)->toHaveCount(2); // the design without a discussion was left alone

    $this->artisan('forum:sync-visibility')->assertFailed(); // still broken, still reports it

    app()->instance(App\Services\Forum\ForumClient::class, new FakeForumClient(false));
    $this->artisan('forum:sync-visibility')->expectsOutputToContain('The forum is disabled')->assertFailed();
});
