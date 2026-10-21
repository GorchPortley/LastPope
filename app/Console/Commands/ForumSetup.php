<?php

namespace App\Console\Commands;

use App\Jobs\CreateForumDiscussion;
use App\Models\Design;
use App\Models\Driver;
use App\Models\Listing;
use App\Services\Forum\ForumClient;
use App\Services\Forum\ForumException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ForumSetup extends Command
{
    protected $signature = 'forum:setup
        {--check : Only report the status, do not create anything}
        {--backfill : Also queue a discussion for every public design, driver and listing that has none yet}';

    protected $description = 'Check the connection to the Flarum forum and create the tags used for automatic discussions';

    public function handle(ForumClient $forum): int
    {
        if (! $forum->enabled()) {
            $this->components->error('The forum is disabled. Set FORUM_ENABLED=true (and FLARUM_URL, FLARUM_INTERNAL_URL, FLARUM_API_KEY) in .env, then try again.');

            return self::FAILURE;
        }

        /** @var array<int, array{0: string, 1: string, 2: string}> $rows */
        $rows = [];
        $ok = true;

        $rows[] = ['Public forum URL', 'info', $forum->forumUrl()];
        $rows[] = ['API URL (server to server)', 'info', rtrim((string) config('forum.internal_url'), '/').'/api'];

        $connection = $forum->checkConnection();
        $rows[] = [
            'Forum reachable',
            $connection['reachable'] ? 'ok' : 'FAILED',
            $connection['reachable']
                ? trim(($connection['title'] ?? '').' '.($connection['version'] ? 'Flarum '.$connection['version'] : ''))
                : $connection['message'],
        ];
        $rows[] = [
            'API key accepted',
            $connection['authorized'] ? 'ok' : 'FAILED',
            $connection['authorized'] ? 'acting as forum user #'.config('forum.admin_user_id') : $connection['message'],
        ];

        $ok = $connection['reachable'] && $connection['authorized'];

        if ($ok) {
            try {
                $existing = $forum->tags();

                foreach (config('forum.tags') as $kind => $tag) {
                    $slug = trim((string) $tag['slug']);

                    if ($slug === '') {
                        $rows[] = ["Tag for {$kind}s", 'skipped', 'slug is empty: no automatic discussions for this kind'];

                        continue;
                    }

                    if (isset($existing[$slug])) {
                        if ($existing[$slug]['primary']) {
                            $rows[] = ["Tag for {$kind}s ({$slug})", 'ok', 'exists (id '.$existing[$slug]['id'].')'];
                        } else {
                            $rows[] = ["Tag for {$kind}s ({$slug})", 'FAILED', 'exists but is a secondary tag; in the forum administration, Tags, drag it into the primary list'];
                            $ok = false;
                        }
                    } elseif ($this->option('check')) {
                        $rows[] = ["Tag for {$kind}s ({$slug})", 'MISSING', 'run forum:setup without --check to create it'];
                        $ok = false;
                    } else {
                        $id = $forum->createTag($slug, $tag['name'], $tag['description'], $tag['color']);
                        $rows[] = ["Tag for {$kind}s ({$slug})", 'created', 'id '.$id];
                    }
                }
            } catch (ForumException $e) {
                $rows[] = ['Tags', 'FAILED', $e->getMessage()];
                $ok = false;
            }
        } else {
            $rows[] = ['Tags', 'skipped', 'needs a working connection and API key'];
        }

        $this->table(['Check', 'Status', 'Details'], $rows);

        if ($ok) {
            $this->components->info('Forum is ready.');

            if ($this->option('backfill') && ! $this->option('check')) {
                $this->backfill();
            }

            return self::SUCCESS;
        }

        $this->components->error('The forum is not ready; see the table above.');

        return self::FAILURE;
    }

    /** Queue discussions for items that were published before the forum was connected. */
    private function backfill(): void
    {
        $queued = 0;

        foreach ([Design::class, Driver::class, Listing::class] as $model) {
            if (! class_exists($model) || ! Schema::hasColumn((new $model)->getTable(), 'forum_slug')) {
                continue;
            }

            $model::query()->whereNull('forum_slug')->each(function ($item) use (&$queued) {
                if (CreateForumDiscussion::supports($item) && CreateForumDiscussion::isPublic($item)) {
                    CreateForumDiscussion::dispatch($item);
                    $queued++;
                }
            });
        }

        $this->components->info("Queued {$queued} discussion(s); the queue worker creates them.");
    }
}
