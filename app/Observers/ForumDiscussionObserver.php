<?php

namespace App\Observers;

use App\Jobs\CreateForumDiscussion;
use App\Services\Forum\ForumClient;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared logic of the Design/Driver/Listing observers. The work happens in a queued job that
 * uses the item's owner as the discussion author, so nothing here depends on auth().
 */
abstract class ForumDiscussionObserver
{
    protected function openDiscussion(Model $subject): void
    {
        if (! app(ForumClient::class)->enabled()) {
            return;
        }

        if (filled($subject->forum_slug) || ! CreateForumDiscussion::wantsThread($subject) || ! CreateForumDiscussion::supports($subject)) {
            return;
        }

        CreateForumDiscussion::dispatch($subject);
    }
}
