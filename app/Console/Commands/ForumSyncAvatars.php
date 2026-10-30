<?php

namespace App\Console\Commands;

use App\Jobs\SyncForumAvatar;
use App\Models\User;
use App\Services\Forum\ForumClient;
use Illuminate\Console\Command;

/**
 * Queues an avatar upload to the forum for every user (their site avatar, or the site's default one). Runs once by
 * itself after the deploy that introduced it (a migration calls it); run it again after a forum reset or restore.
 * Safe to repeat.
 */
class ForumSyncAvatars extends Command
{
    protected $signature = 'forum:sync-avatars {--user= : Only this user id}';

    protected $description = 'Give every forum account the same avatar as on the site';

    public function handle(ForumClient $forum): int
    {
        if (! $forum->enabled()) {
            $this->components->info('The forum is disabled; nothing to do.');

            return self::SUCCESS;
        }

        $count = 0;

        User::query()
            ->when($this->option('user'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->chunkById(200, function ($users) use (&$count): void {
                foreach ($users as $user) {
                    SyncForumAvatar::dispatch($user);
                    $count++;
                }
            });

        $this->components->info("Queued the avatar of {$count} ".str('user')->plural($count).'. The queue worker uploads them.');

        return self::SUCCESS;
    }
}
