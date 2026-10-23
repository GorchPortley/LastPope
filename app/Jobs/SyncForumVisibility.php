<?php

namespace App\Jobs;

use App\Models\Design;
use App\Services\Forum\DesignDiscussionAudience;
use App\Services\Forum\ForumClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Makes the forum discussion of a design as private as the design is (see DesignDiscussionAudience): public for a
 * free design that is on show, otherwise private to the people that audience lists.
 *
 * Idempotent and stateless: every run works out what the discussion should look like from the database and applies
 * that, whatever the forum looks like now. Queued by the design observer (active status or price changed), the
 * entitlement observer (buyer added or refunded), CreateForumDiscussion (after it opened the thread) and
 * `forum:sync-visibility`.
 *
 * A job that is waiting is not queued twice (it reads the database when it runs), but a change that arrives while
 * one is running queues another, and runs for one design never overlap, so the last run always has the newest data.
 */
class SyncForumVisibility implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Waiting for another run to finish counts as an attempt; only real errors count towards the failure.
    public int $tries = 10;

    public int $maxExceptions = 3;

    public int $uniqueFor = 600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Design $design)
    {
        $this->afterCommit();
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function uniqueId(): string
    {
        return (string) $this->design->getKey();
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->design->getKey()))->releaseAfter(15)->expireAfter(300)];
    }

    /**
     * Queue a run when the forum is on and the design has a discussion; a design without one is handled when it gets one.
     * Called while a design is saved or a purchase is booked, which a forum or queue problem must never break.
     */
    public static function queueFor(Design $design): void
    {
        if (blank($design->forum_slug) || ! app(ForumClient::class)->enabled()) {
            return;
        }

        try {
            self::dispatch($design);
        } catch (\Throwable $e) {
            Log::error('Could not queue the forum visibility sync.', ['design' => $design->getKey(), 'error' => $e->getMessage()]);
        }
    }

    public function handle(ForumClient $forum): void
    {
        if (! $forum->enabled()) {
            return;
        }

        $design = $this->design->fresh();

        // Deleted meanwhile, or no discussion yet: CreateForumDiscussion queues this job once it has opened one.
        if ($design === null || blank($design->forum_slug)) {
            return;
        }

        if (DesignDiscussionAudience::isPublic($design)) {
            $forum->makeDiscussionPublic($design->forum_slug);
        } else {
            $forum->makeDiscussionPrivate($design->forum_slug, DesignDiscussionAudience::members($design));
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Could not set who can see the forum discussion of a design.', [
            'design' => $this->design->getKey(),
            'error' => $exception?->getMessage(),
        ]);
    }
}
