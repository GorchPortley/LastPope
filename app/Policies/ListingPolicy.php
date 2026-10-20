<?php

namespace App\Policies;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;

class ListingPolicy
{
    /** Admins (platform sellers) may do everything, which also keeps the Filament admin resources working. */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isPlatformSeller() ? true : null;
    }

    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Listing $listing): bool
    {
        return in_array($listing->status, [ListingStatus::ACTIVE, ListingStatus::SOLD_OUT, ListingStatus::PAUSED], true)
            || ($user !== null && $listing->seller_id === $user->id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Listing $listing): bool
    {
        return $listing->seller_id === $user->id;
    }

    public function delete(User $user, Listing $listing): bool
    {
        return $listing->seller_id === $user->id;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /** Listings are physical goods: there is nothing to download. */
    public function download(User $user, Listing $listing): bool
    {
        return false;
    }
}
