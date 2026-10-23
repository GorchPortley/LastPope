<?php

namespace App\Console\Commands;

use App\Jobs\CreateForumDiscussion;
use App\Jobs\SyncForumVisibility;
use App\Models\Design;
use App\Models\Driver;
use App\Models\Listing;
use App\Models\User;
use App\Services\Forum\DesignDiscussionAudience;
use App\Services\Forum\ForumClient;
use App\Services\Forum\ForumException;
use App\Services\Forum\ForumTagSetup;
use Closure;
use Database\Seeders\Demo\DemoCatalog;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Fills the forum of a demo/dev site: makes sure the tags exist, creates the missing thread of every design and
 * every public driver and listing right now (as its owner, the same code as the CreateForumDiscussion job), sets who
 * can see each design thread (hidden and paid designs are private, see DesignDiscussionAudience), then gives every
 * thread that has no reply yet 2-4 replies from the other demo users, taken from the bank of hobbyist comments in
 * DemoCatalog::forumComments(). A private thread only gets replies from demo users who can see it (the buyers and
 * the admin), a hidden design's none.
 *
 * Runs in the local and demo environments only (and testing, like DemoSeeder), never in production.
 *
 * Idempotent: threads that exist are kept, threads that already have replies are skipped, so a second run
 * adds nothing. Which comment and which user goes where is derived from the item, not random.
 *
 * Flarum 2 does not throttle requests made with the master API key, so a run takes about a minute. Should a forum
 * answer "slow down" (HTTP 429, e.g. older Flarum or a rate limit added by an extension), the command takes turns
 * between members and waits (forum.throttle_wait seconds) before it carries on.
 */
class ForumSeedDemo extends Command
{
    protected $signature = 'forum:seed-demo';

    protected $description = 'Demo/dev only: create forum threads for designs, public drivers and listings and add demo replies';

    /** Demo accounts all have an address in this domain (see DemoCatalog::users()). */
    private const DEMO_DOMAIN = '@sdlabs.test';

    /** How often one step may be told to slow down before it is given up on. */
    private const MAX_THROTTLED_TRIES = 8;

    private int $threadsCreated = 0;

    private int $threadsExisting = 0;

    private int $repliesPosted = 0;

    private int $threadsWithReplies = 0;

    public function handle(ForumClient $forum, ForumTagSetup $tags): int
    {
        // the command object lives as long as the application: start every run from zero
        $this->threadsCreated = $this->threadsExisting = $this->repliesPosted = $this->threadsWithReplies = 0;

        if (! app()->environment(['local', 'demo', 'testing'])) {
            $this->components->error('forum:seed-demo only runs in the local and demo environments (APP_ENV is "'.app()->environment().'").');

            return self::FAILURE;
        }

        if (! $forum->enabled()) {
            $this->components->error('The forum is disabled. Set FORUM_ENABLED=true (and FLARUM_URL, FLARUM_INTERNAL_URL, FLARUM_API_KEY) in .env, run forum:setup, then try again.');

            return self::FAILURE;
        }

        $connection = $forum->checkConnection();

        if (! $connection['reachable'] || ! $connection['authorized']) {
            $this->components->error('The forum is not ready: '.$connection['message'].' Run forum:setup to see what is wrong.');

            return self::FAILURE;
        }

        $setup = $tags->ensure($forum);

        if (! $setup['ok']) {
            $this->table(['Check', 'Status', 'Details'], $setup['rows']);
            $this->components->error('The forum tags are not ready; run forum:setup.');

            return self::FAILURE;
        }

        $failures = $this->createThreads($forum);
        $failures = array_merge($failures, $this->syncVisibility($forum), $this->addReplies($forum));

        $this->table(['Result', 'Count'], [
            ['Threads created', $this->threadsCreated],
            ['Threads that already existed', $this->threadsExisting],
            ['Replies posted', $this->repliesPosted],
            ['Threads that already had replies', $this->threadsWithReplies],
        ]);

        foreach ($failures as $failure) {
            $this->components->warn($failure);
        }

        if ($failures !== []) {
            $this->components->error(count($failures).' step(s) failed; run the command again to retry them.');

            return self::FAILURE;
        }

        $this->components->info($this->threadsCreated + $this->repliesPosted === 0 ? 'Nothing to do: the forum already has the demo content.' : 'Forum demo content is ready.');

        return self::SUCCESS;
    }

    // --- threads ------------------------------------------------------------------------------

    /** @return array<int, string> failures */
    private function createThreads(ForumClient $forum): array
    {
        $chains = [];

        foreach ($this->subjects() as $subject) {
            if (filled($subject->forum_slug)) {
                $this->threadsExisting++;

                continue;
            }

            $chains[] = [
                'label' => $this->label($subject),
                'steps' => [[
                    'author' => (int) CreateForumDiscussion::ownerOf($subject)->getKey(),
                    'run' => function () use ($subject, $forum): void {
                        (new CreateForumDiscussion($subject))->handle($forum);

                        if (blank($subject->fresh()?->forum_slug)) {
                            throw new ForumException('the discussion was not created.');
                        }

                        $this->threadsCreated++;
                    },
                ]],
            ];
        }

        $this->components->info(count($chains) === 0 ? 'Every design and every public driver and listing already has a thread.' : 'Creating '.count($chains).' thread(s)...');

        return $this->drain($chains);
    }

    /**
     * Set who can see each design thread now, so the replies below meet the final state (the job CreateForumDiscussion
     * queues for this may not have run yet). Idempotent, and it repairs threads whose state drifted.
     *
     * @return array<int, string> failures
     */
    private function syncVisibility(ForumClient $forum): array
    {
        $failures = [];

        foreach ($this->subjects()->whereInstanceOf(Design::class) as $design) {
            if (blank($design->forum_slug)) {
                continue; // its thread could not be created
            }

            try {
                (new SyncForumVisibility($design))->handle($forum);
            } catch (Throwable $e) {
                $failures[] = $this->label($design).': '.$e->getMessage();
            }
        }

        return $failures;
    }

    // --- replies ------------------------------------------------------------------------------

    /** @return array<int, string> failures */
    private function addReplies(ForumClient $forum): array
    {
        $demoUsers = User::query()->where('email', 'like', '%'.self::DEMO_DOMAIN)->orderBy('id')->get();
        $failures = [];
        $chains = [];

        foreach ($this->subjects() as $subject) {
            if (blank($subject->forum_slug)) {
                continue; // its thread could not be created
            }

            if ($subject instanceof Design && ! $subject->active) {
                continue; // a hidden design: nobody to talk to yet
            }

            try {
                $existing = $forum->replyCount($subject->forum_slug);
            } catch (Throwable $e) {
                $failures[] = $this->label($subject).': '.$e->getMessage();

                continue;
            }

            if ($existing === null) {
                $failures[] = $this->label($subject).": the forum does not know discussion '{$subject->forum_slug}'.";

                continue;
            }

            if ($existing > 0) {
                $this->threadsWithReplies++;

                continue;
            }

            $steps = [];
            foreach ($this->plan($subject, $demoUsers) as [$author, $content]) {
                $steps[] = [
                    'author' => (int) $author->getKey(),
                    'run' => function () use ($forum, $subject, $content, $author): void {
                        $forum->createPost($subject->forum_slug, $content, $author);
                        $this->repliesPosted++;
                    },
                ];
            }

            if ($steps !== []) {
                $chains[] = ['label' => $this->label($subject), 'steps' => $steps];
            }
        }

        $this->components->info(count($chains) === 0 ? 'Every thread already has replies.' : 'Adding replies to '.count($chains).' thread(s)...');

        return array_merge($failures, $this->drain($chains));
    }

    /**
     * The replies a thread gets: 2-4 of them, from demo users other than the owner (for a private design thread,
     * only those who can see it; fewer replies when there are few of them). Designs and drivers get comments in
     * turn from different members; a listing gets buyer questions that its seller answers.
     *
     * @param  Collection<int, User>  $demoUsers
     * @return array<int, array{0: User, 1: string}>
     */
    public function plan(Model $subject, Collection $demoUsers): array
    {
        $owner = CreateForumDiscussion::ownerOf($subject);
        $others = $demoUsers->reject(fn (User $user) => $owner !== null && $user->is($owner))->values();

        if ($subject instanceof Design && ! DesignDiscussionAudience::isPublic($subject)) {
            $members = DesignDiscussionAudience::members($subject);
            $others = $others->filter(fn (User $user) => $members->contains($user))->values();
        }

        if ($others->isEmpty() || ! $owner instanceof User) {
            return [];
        }

        $kind = $this->kind($subject);
        $hash = crc32($kind.':'.$subject->getKey());
        $count = 2 + $hash % 3;
        $bank = DemoCatalog::forumComments();

        if ($kind === 'listing') {
            $pairs = $bank['listing'];
            $first = $hash % count($pairs);
            $second = ($first + 2) % count($pairs);

            return array_slice([
                [$others[$hash % $others->count()], $pairs[$first][0]],
                [$owner, $pairs[$first][1]],
                [$others[($hash + 1) % $others->count()], $pairs[$second][0]],
                [$owner, $pairs[$second][1]],
            ], 0, $count);
        }

        $comments = $bank[$kind];
        $title = CreateForumDiscussion::titleFor($subject);
        $replies = [];

        // one person should not carry a whole thread
        for ($i = 0; $i < min($count, 2 * $others->count()); $i++) {
            $replies[] = [
                $others[($hash + $i) % $others->count()],
                str_replace('{item}', $title, $comments[($hash + $i * 3) % count($comments)]),
            ];
        }

        return $replies;
    }

    // --- helpers ------------------------------------------------------------------------------

    /**
     * Every item that gets a thread: all designs (hidden ones too), active drivers, active listings (store and marketplace).
     *
     * @return Collection<int, Model>
     */
    private function subjects(): Collection
    {
        return collect([Design::class, Driver::class, Listing::class])
            ->flatMap(fn (string $class) => $class::query()->orderBy('id')->get())
            ->filter(fn (Model $item) => CreateForumDiscussion::supports($item)
                && CreateForumDiscussion::wantsThread($item)
                && CreateForumDiscussion::ownerOf($item) instanceof User)
            ->values();
    }

    private function kind(Model $subject): string
    {
        return match (true) {
            $subject instanceof Design => 'design',
            $subject instanceof Driver => 'driver',
            default => 'listing',
        };
    }

    private function label(Model $subject): string
    {
        return $this->kind($subject).' "'.CreateForumDiscussion::titleFor($subject).'"';
    }

    /**
     * Run chains of steps (a chain = the thread-creating step, or the replies of one thread in order).
     * Takes turns between authors; a step that Flarum throttles puts its author on hold and the loop moves on
     * to somebody else, waiting only when everybody left is on hold. Any other error drops the chain.
     *
     * @param  array<int, array{label: string, steps: array<int, array{author: int, run: Closure}>}>  $chains
     * @return array<int, string> failure messages
     */
    private function drain(array $chains): array
    {
        $chains = $this->interleave($chains);
        $failures = [];
        $onHold = [];
        $tries = [];
        $pause = max(0.0, (float) config('forum.throttle_wait', 11));

        while ($chains !== []) {
            $progressed = false;
            $resumeAt = null;

            foreach (array_keys($chains) as $key) {
                $step = $chains[$key]['steps'][0];
                $until = $onHold[$step['author']] ?? 0.0;

                if ($until > microtime(true)) {
                    $resumeAt = min($resumeAt ?? $until, $until);

                    continue;
                }

                try {
                    $step['run']();
                    array_shift($chains[$key]['steps']);
                    $progressed = true;
                } catch (Throwable $e) {
                    $tries[$key] = ($tries[$key] ?? 0) + 1;

                    if ($e instanceof ForumException && $e->isThrottled() && $tries[$key] <= self::MAX_THROTTLED_TRIES) {
                        $onHold[$step['author']] = microtime(true) + $pause;
                        $resumeAt = min($resumeAt ?? $onHold[$step['author']], $onHold[$step['author']]);
                    } else {
                        $failures[] = $chains[$key]['label'].': '.$e->getMessage();
                        unset($chains[$key]);
                    }

                    continue;
                }

                if ($chains[$key]['steps'] === []) {
                    unset($chains[$key]);
                }
            }

            if (! $progressed && $chains !== [] && $resumeAt !== null) {
                $this->wait($resumeAt - microtime(true));
            }
        }

        return $failures;
    }

    /**
     * Order chains so that consecutive ones belong to different authors: member A, member B, member C, A, B, C...
     *
     * @param  array<int, array{label: string, steps: array<int, array{author: int, run: Closure}>}>  $chains
     * @return array<int, array{label: string, steps: array<int, array{author: int, run: Closure}>}>
     */
    private function interleave(array $chains): array
    {
        $byAuthor = [];
        foreach ($chains as $chain) {
            $byAuthor[$chain['steps'][0]['author']][] = $chain;
        }

        $ordered = [];
        while ($byAuthor !== []) {
            foreach (array_keys($byAuthor) as $author) {
                $ordered[] = array_shift($byAuthor[$author]);

                if ($byAuthor[$author] === []) {
                    unset($byAuthor[$author]);
                }
            }
        }

        return $ordered;
    }

    protected function wait(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) ceil($seconds * 1_000_000));
        }
    }
}
