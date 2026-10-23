<?php

namespace App\Console\Commands;

use App\Jobs\SyncForumVisibility;
use App\Models\Design;
use App\Services\Forum\DesignDiscussionAudience;
use App\Services\Forum\ForumClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sets who can see the forum discussion of every design, from the database (docs/PLAN.md, R5.2): the same work the
 * SyncForumVisibility job does when a design or its buyers change, for all designs at once. Run it after a forum
 * reset or restore, after switching the forum on for a site that has sold designs, or when an admin was added or
 * removed (their access to existing discussions follows the next sync). Safe to repeat.
 */
class ForumSyncVisibility extends Command
{
    protected $signature = 'forum:sync-visibility';

    protected $description = 'Make every design discussion public, or private to its designer, buyers and the admins';

    public function handle(ForumClient $forum): int
    {
        if (! $forum->enabled()) {
            $this->components->error('The forum is disabled. Set FORUM_ENABLED=true (and FLARUM_URL, FLARUM_INTERNAL_URL, FLARUM_API_KEY) in .env, then try again.');

            return self::FAILURE;
        }

        $connection = $forum->checkConnection();

        if (! $connection['reachable'] || ! $connection['authorized']) {
            $this->components->error('The forum is not ready: '.$connection['message'].' Run forum:setup to see what is wrong.');

            return self::FAILURE;
        }

        $public = $private = 0;
        $failures = [];

        $designs = Design::query()->whereNotNull('forum_slug')->where('forum_slug', '!=', '')->orderBy('id')->get();

        foreach ($designs as $design) {
            try {
                (new SyncForumVisibility($design))->handle($forum);

                DesignDiscussionAudience::isPublic($design) ? $public++ : $private++;
            } catch (Throwable $e) {
                $failures[] = "design #{$design->getKey()} \"{$design->name}\": ".$e->getMessage();
            }
        }

        $this->table(['Result', 'Designs'], [
            ['Discussion public', $public],
            ['Discussion private (designer, buyers, admins)', $private],
            ['Failed', count($failures)],
            ['Without a discussion yet (forum:setup --backfill creates them)', Design::query()->where(fn ($query) => $query->whereNull('forum_slug')->orWhere('forum_slug', ''))->count()],
        ]);

        foreach ($failures as $failure) {
            $this->components->warn($failure);
        }

        if ($failures !== []) {
            $this->components->error(count($failures).' design(s) failed; run the command again to retry them.');

            return self::FAILURE;
        }

        $this->components->info('Done.');

        return self::SUCCESS;
    }
}
