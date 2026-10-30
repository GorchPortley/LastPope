<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Forum\ForumClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gives the user's forum account the same avatar as the site (their own picture or the site's default one).
 * Queued when the avatar changes, after a new forum account is made, and for everybody by `forum:sync-avatars`.
 */
class SyncForumAvatar implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public User $user)
    {
        $this->afterCommit();
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function uniqueId(): string
    {
        return (string) $this->user->getKey();
    }

    public function handle(ForumClient $forum): void
    {
        if ($forum->enabled()) {
            $forum->syncAvatar($this->user->fresh() ?? $this->user);
        }
    }

    /** Queue it when the forum is on; a forum or queue problem never breaks saving a user. */
    public static function queueFor(User $user): void
    {
        if (! app(ForumClient::class)->enabled()) {
            return;
        }

        try {
            self::dispatch($user);
        } catch (Throwable $e) {
            Log::error('Could not queue the forum avatar sync.', ['user' => $user->getKey(), 'error' => $e->getMessage()]);
        }
    }
}
