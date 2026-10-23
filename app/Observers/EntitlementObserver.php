<?php

namespace App\Observers;

use App\Jobs\SyncForumVisibility;
use App\Models\Design;
use App\Models\Entitlement;
use App\Services\Forum\DesignDiscussionAudience;

/**
 * The buyers of a paid design are the people allowed in its forum discussion, so a new entitlement (a purchase), a
 * revoked one (a refund) or a restored one queues a visibility sync. This catches every way an entitlement changes:
 * User::grantEntitlement / revokeEntitlement, the admin screens, direct saves.
 */
class EntitlementObserver
{
    public function saved(Entitlement $entitlement): void
    {
        if ($entitlement->wasRecentlyCreated || $entitlement->wasChanged('revoked_at')) {
            $this->sync($entitlement);
        }
    }

    public function deleted(Entitlement $entitlement): void
    {
        $this->sync($entitlement);
    }

    private function sync(Entitlement $entitlement): void
    {
        if ($entitlement->entitleable_type !== (new Design)->getMorphClass()) {
            return;
        }

        $design = $entitlement->entitleable;

        // Free and hidden designs have the same audience whoever holds an entitlement.
        if ($design instanceof Design && DesignDiscussionAudience::dependsOnBuyers($design)) {
            SyncForumVisibility::queueFor($design);
        }
    }
}
