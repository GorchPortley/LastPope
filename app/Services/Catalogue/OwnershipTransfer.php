<?php

namespace App\Services\Catalogue;

use App\Enums\EntitlementSource;
use App\Jobs\SyncForumVisibility;
use App\Models\Design;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hands a design or driver to another user (admin action), with the loose ends a plain user_id change would leave:
 *
 *  - a new owner with the manufacturer role marks the item official;
 *  - the old owner of a paid design can keep its files through an admin-granted entitlement;
 *  - the private forum discussion of a design gets its member list resynced (the Flarum author stays as it was).
 *
 * Past sales are not touched: every seller order keeps the seller it was paid to, so earnings and pending payouts stay
 * with the old owner. Snapshots keep the old designer's name until a new one is built.
 */
class OwnershipTransfer
{
    public function transfer(Design|Driver $item, User $to, User $actor, bool $oldOwnerKeepsCopy = true): void
    {
        $from = $item->getAttribute('user_id');

        if ((int) $from === (int) $to->getKey()) {
            return;
        }

        DB::transaction(function () use ($item, $to, $from, $oldOwnerKeepsCopy): void {
            $item->user_id = $to->getKey();

            if ($to->hasRole('manufacturer')) {
                $item->official = true;
            }

            $item->save();

            $previous = $from ? User::find($from) : null;

            if ($item instanceof Design && $oldOwnerKeepsCopy && $previous && ! $item->isFree()) {
                $previous->grantEntitlement($item, EntitlementSource::ADMIN_GRANT);
            }
        });

        if ($item instanceof Design) {
            SyncForumVisibility::queueFor($item);
        }

        Log::info('Catalogue item transferred to a new owner.', [
            'type' => $item instanceof Design ? 'design' : 'driver',
            'id' => $item->getKey(),
            'from_user_id' => $from,
            'to_user_id' => $to->getKey(),
            'by_user_id' => $actor->getKey(),
        ]);
    }
}
