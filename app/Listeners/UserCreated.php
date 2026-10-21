<?php

namespace App\Listeners;

use App\Services\Forum\ForumClient;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Creates the member's forum account right after registration (on the queue, so signing up never
 * waits for the forum). Logging in does the same lazily, so it does not matter which runs first.
 */
class UserCreated implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(private readonly ForumClient $forum)
    {
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(Registered $event): void
    {
        if (! $this->forum->enabled()) {
            return;
        }

        if ($this->forum->ensureUser($event->user) === null && $this->attempts() < $this->tries) {
            $this->release(30);
        }
    }
}
