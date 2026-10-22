<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forgets what the site remembers about the forum: the discussion of every design, driver and listing
 * (forum_slug) and the forum account of every member (users.forum_user_id).
 *
 * Needed after the forum was wiped (docker/flarum/reset.sh runs it): the stored slugs and ids then point at
 * discussions and accounts that no longer exist, and the pages would embed threads that give a 404. Afterwards
 * `forum:setup --backfill` (or `forum:seed-demo` on a demo site) creates fresh discussions. Members get their
 * forum account again the next time they log in. The members' stored forum passwords are kept.
 */
class ForumUnlink extends Command
{
    protected $signature = 'forum:unlink {--force : Do not ask for confirmation}';

    protected $description = 'Forget the stored forum discussions and forum accounts (run after the forum was reset)';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Forget every stored forum discussion and forum account? Run this only when the forum itself was wiped.')) {
            return self::FAILURE;
        }

        $rows = [];

        foreach ([['designs', 'forum_slug'], ['drivers', 'forum_slug'], ['listings', 'forum_slug'], ['users', 'forum_user_id']] as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            // Plain queries on purpose: no model events (observers would queue new discussions) and no touched timestamps.
            $rows[] = ["{$table}.{$column}", DB::table($table)->whereNotNull($column)->update([$column => null])];
        }

        $this->table(['Cleared', 'Rows'], $rows);

        $this->components->info('Done. Now create the forum content again: `php artisan forum:setup --backfill` (or `forum:seed-demo` on a demo site).');

        return self::SUCCESS;
    }
}
