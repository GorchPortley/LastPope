<?php

namespace App\Observers;

use App\Jobs\SyncForumVisibility;
use App\Models\Design;

/**
 * Opens the forum discussion of a design as soon as it exists (a hidden design's discussion is private to its
 * designer and the admins), and keeps who can see it in line when the design is shown, hidden or re-priced.
 */
class DesignObserver extends ForumDiscussionObserver
{
    public function saved(Design $design): void
    {
        $this->openDiscussion($design);

        if ($design->wasChanged(['active', 'price_cents'])) {
            SyncForumVisibility::queueFor($design);
        }
    }
}
