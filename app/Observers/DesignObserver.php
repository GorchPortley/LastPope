<?php

namespace App\Observers;

use App\Models\Design;

/**
 * Opens the forum discussion once a design is public (active) and has none yet.
 * Drafts get no thread, so unpublished work never shows up in the forum.
 */
class DesignObserver extends ForumDiscussionObserver
{
    public function saved(Design $design): void
    {
        $this->openDiscussion($design);
    }
}
