<?php

namespace App\Jobs;

use App\Enums\ListingStatus;
use App\Models\Design;
use App\Models\Driver;
use App\Models\Listing;
use App\Models\User;
use App\Services\Forum\ForumClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Opens the forum discussion for a design, a driver or a listing and stores the discussion slug
 * ("{id}-{title}") in the item's forum_slug column, which the pages use for the embedded thread.
 *
 * The discussion is authored by the item's owner (designer / creator / seller), never by whoever
 * happens to be logged in: this runs on the queue, where there is no logged-in user. It always starts
 * public, in the item's tag (members cannot start private discussions); a design's thread is then made
 * as private as the design (SyncForumVisibility) in the same run.
 */
class CreateForumDiscussion implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public Model $subject)
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
        return $this->subject::class.':'.$this->subject->getKey();
    }

    /** True when this kind of item gets an automatic discussion (a tag is configured and forum_slug can be stored). */
    public static function supports(Model $subject): bool
    {
        static $columns = [];

        $table = $subject->getTable();
        $columns[$table] ??= Schema::hasColumn($table, 'forum_slug');

        return $columns[$table] && self::tagSlugFor($subject) !== '';
    }

    public function handle(ForumClient $forum): void
    {
        if (! $forum->enabled()) {
            return;
        }

        $subject = $this->subject->fresh();

        // Deleted meanwhile, already has a thread (earlier run / duplicate job), or not ready for one (yet).
        if ($subject === null || ! self::supports($subject) || filled($subject->forum_slug) || ! self::wantsThread($subject)) {
            return;
        }

        $tagSlug = self::tagSlugFor($subject);
        $author = self::ownerOf($subject);

        if ($tagSlug === '' || ! $author instanceof User) {
            return;
        }

        $slug = $forum->createDiscussion(
            self::titleFor($subject),
            self::contentFor($subject),
            $tagSlug,
            $author,
        );

        // Quietly: this must not trigger the observer (or search indexing) again.
        $subject->forceFill(['forum_slug' => $slug])->saveQuietly();

        if ($subject instanceof Design) {
            $this->restrict($subject, $forum);
        }
    }

    /**
     * Make a new design thread as private as the design right away, in this run, so a hidden or paid design's thread
     * is public only for the moment between the two requests instead of until the queue gets to the sync job.
     * When that fails, the queued sync (with its retries) takes over.
     */
    private function restrict(Design $design, ForumClient $forum): void
    {
        try {
            (new SyncForumVisibility($design))->handle($forum);
        } catch (\Throwable $e) {
            report($e);
            SyncForumVisibility::queueFor($design);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Could not create the forum discussion.', [
            'subject' => $this->subject::class.'#'.$this->subject->getKey(),
            'error' => $exception?->getMessage(),
        ]);
    }

    // --- per kind of item ---------------------------------------------------------------------

    public static function tagSlugFor(Model $subject): string
    {
        $kind = match (true) {
            $subject instanceof Design => 'design',
            $subject instanceof Driver => 'driver',
            $subject instanceof Listing => 'listing',
            default => null,
        };

        return $kind === null ? '' : trim((string) config("forum.tags.{$kind}.slug"));
    }

    /**
     * Is the item ready for its discussion? A design gets one from the day it is created, hidden or not (a hidden
     * design's discussion is private to its designer and the admins); drivers and listings wait until they are public.
     */
    public static function wantsThread(Model $subject): bool
    {
        return match (true) {
            $subject instanceof Design => true,
            $subject instanceof Listing => $subject->status === ListingStatus::ACTIVE,
            default => (bool) $subject->active,
        };
    }

    public static function ownerOf(Model $subject): ?User
    {
        return match (true) {
            $subject instanceof Design => $subject->designer,
            $subject instanceof Driver => $subject->creator,
            $subject instanceof Listing => $subject->seller,
            default => null,
        };
    }

    public static function titleFor(Model $subject): string
    {
        return match (true) {
            $subject instanceof Driver => trim($subject->brand.' '.$subject->model) ?: 'Driver',
            $subject instanceof Listing => (string) $subject->title,
            default => (string) ($subject->name ?: 'Untitled design'),
        };
    }

    public static function contentFor(Model $subject): string
    {
        [$intro, $url] = match (true) {
            $subject instanceof Design => ['New design posted', url('/designs/design/'.$subject->getKey())],
            $subject instanceof Driver => ['New driver posted', url('/drivers/driver/'.$subject->getKey())],
            default => ['New listing posted', url('/marketplace/listing/'.($subject->uuid ?? $subject->getKey()))],
        };

        $summary = Str::limit(trim(strip_tags((string) ($subject->summary ?? $subject->description ?? ''))), 600);

        return trim($intro.($summary !== '' ? ": {$summary}" : '.')."\n\nView it here: {$url}");
    }
}
