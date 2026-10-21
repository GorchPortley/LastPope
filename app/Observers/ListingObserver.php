<?php

namespace App\Observers;

use App\Models\Listing;

/**
 * Opens the forum discussion once a listing is active and has none yet. Needs a nullable
 * listings.forum_slug column and a FORUM_LISTING_TAG; without either, nothing happens.
 */
class ListingObserver extends ForumDiscussionObserver
{
    public function saved(Listing $listing): void
    {
        $this->openDiscussion($listing);
    }
}
