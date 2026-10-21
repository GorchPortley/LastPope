<?php

namespace App\Observers;

use App\Models\Driver;

/** Opens the forum discussion once a driver is public (active) and has none yet. */
class DriverObserver extends ForumDiscussionObserver
{
    public function saved(Driver $driver): void
    {
        $this->openDiscussion($driver);
    }
}
